# Research: Context Firewall

## R1. Pipeline order is fixed in code

- **Decision**: Stages run in this order inside `GateCallTool`, after authorization and argument
  validation from spec 001:
  1. **Fetch** — the tool handler returns raw rows (the only place raw data exists).
  2. **Classify check** — unclassified fields dropped; fields above the caller ceiling dropped (FR-003, FR-007).
  3. **Project** — only contract fields kept, in contract order.
  4. **Relations** — related records loaded one level deep through their contract (FR-011, FR-012).
  5. **Transform** — pseudonyms, buckets, date precision (FR-006, FR-008).
  6. **Normalize** — NFKC, then invisible characters removed (FR-013).
  7. **Redact** — regular-expression layer per text field, fail closed per field (FR-014).
  8. **Canary** — configured canary values searched in the normalized output (FR-017).
  9. **Quarantine** — untrusted wrapper, injection flag (FR-015, FR-016).
  10. **Budget** — per-call truncation, session and daily refusal, rate limit (FR-018–FR-021).
  11. **Receipt** — written before the response is returned (FR-023, constitution IV).
- **Rationale**: Canary check comes after redaction so that a canary split by characters is still seen
  (edge case). Budget comes last so truncation measures the final bytes. Receipt is last so it contains
  the final decision.
- **Alternatives considered**: a middleware pipeline with configurable stages (rejected: constitution VI,
  config cannot change order).

## R2. Contracts are PHP classes

- **Decision**: `ContextContract` is an abstract class with `fields(): array` returning `Field` definitions
  keyed by column name. `Field` offers factories: `Field::pseudonym(prefix)`, `Field::bucket(breaks)`,
  `Field::date(precision)`, `Field::aggregate(count)`, `Field::text()`, `Field::relation(Contract::class)`,
  `Field::plain()` (allowed only for `public` and `internal`).
- **Rationale**: D15. IDE completion and PHPStan check the shape. A contract cannot set a class (FR-002):
  there is no class parameter on any factory.
- **Alternatives considered**: arrays (no static checks).

## R3. Classification catalog

- **Decision**: the catalog is the config key `context-gate.classification`, `[table => [column => class]]`, set in `config/context-gate.php` (read by the code; no file under app/ is loaded). Loaded once per process,
  validated at boot: unknown class value fails; contracts are checked against it on load (FR-004).
- **Rationale**: D14. Single source of truth for DPO review.

## R4. Pseudonyms

- **Decision**: `pseudonym = prefix . '_' . substr(hash_hmac('sha256', scopeKey, "{$table}\0{$id}"), 0, 10)`.
  Session scope: key generated with `random_bytes(32)` and held in memory for the session. P1D and P7D
  scopes: key stored in the application cache encrypted with Laravel's Encrypter, TTL equal to the scope
  length (FR-009). Reverse map (pseudonym → table, id) is held under the same scope and TTL.
- **Collision**: if a short pseudonym already maps to a different record in the scope, the length is
  extended by one hex character until unique (edge case).
- **Failure**: if key generation or encryption fails, the call is refused (`pseudonym.unknown` is not used
  here; reason `pseudonym.keyfail` added), no unkeyed pseudonym is ever issued.
- **Alternatives considered**: a database table of pseudonyms (rejected for MVP: extra migration in every
  service; cache with TTL is enough and expires automatically).

## R5. Resolving pseudonyms as arguments

- **Decision**: `ValidateInput` (spec 001) gains a step: any argument declared as `Pseudonym` is looked up
  in the scope reverse map. Unknown or expired → refused with `pseudonym.unknown`, no record existence
  hint (FR-010).
- **Rationale**: Only the server can map back to a record. The caller cannot enumerate.

## R6. Relations

- **Decision**: `Field::relation()` accepts only a contract class. On boot, the relation's contract is
  checked: if it contains any relation, loading fails with `contract.depth` (FR-011). Eager loading via
  `with()`; the row limit counts related rows (FR-012).
- **Rationale**: D17.

## R7. Redaction and NFKC

- **Decision**: NFKC via `Normalizer::normalize()` from `ext-intl`. `ext-intl` becomes a required
  extension in `composer.json` (see Constitution Check, principle X). Regular-expression layer in
  `src/Privacy/Redactor.php` covers email, phone, card number (Luhn-checked), IBAN, bearer token and
  high-entropy strings. Each text field is redacted separately; an exception drops that field
  (`redaction.failed`) and returns the rest (FR-014).
- **Alternatives considered**: a fullwidth-ASCII fallback without `ext-intl` (rejected: partial
  normalisation is a privacy gap; requiring the extension is simpler and fail-closed).

## R8. Canaries

- **Decision**: canary values come from config (`canaries.values`, read from env by the operator). The
  check runs `str_contains` on the normalized JSON of the output and on the normalized arguments. A match
  blocks the response with the generic failure and records `canary.hit` (FR-017).
- **Rationale**: D22. Checking the serialized output catches values in any field.
- **Note**: canary values are never written to receipts or logs (FR-024).

## R9. Quarantine

- **Decision**: invisible and bidirectional control characters (U+200B–U+200F, U+202A–U+202E,
  U+2060–U+2064, U+FEFF, U+E0000–U+E007F) are removed. Text from sources is wrapped as
  `{"untrusted": true, "source": "...", "text": "..."}`. Injection phrases (the pattern set from
  the roadmap: "ignore previous instructions", "system prompt", "you are now", "call the tool") set
  `injection.phrase` in the response metadata and the receipt; the response is not blocked (FR-016).
- **Rationale**: D21. The flag is a signal, not a control.

## R10. Budgets and rate limit

- **Decision**:
  - Per call: `strlen(json_encode($output))` after the pipeline; above the limit the output is truncated at
    a record boundary and `truncated: true` is set (FR-018).
  - Per session: a counter in the cache keyed by session id, with the session TTL (FR-019).
  - Per token day: a counter in the cache keyed by caller id and UTC date, TTL until 00:00 UTC (FR-020).
  - Per minute: `Laravel\Facades\RateLimiter` keyed by caller id, 60 per minute (FR-021).
  - Hard caps in code: `Caps::PER_CALL = 65536`, `Caps::PER_SESSION = 2097152`,
    `Caps::PER_DAY = 20971520`, `Caps::PER_MINUTE = 300` (FR-022).
- **Rationale**: D23, D25, D31. Limits in the cache store are per application, which matches the
  embedded model (D12).

## R11. Rule hashes in receipts

- **Decision**: at boot, compute `rules.tool` (sha256 of the tool description), `rules.contract` (sha256 of
  the canonical contract definition: field names, forms, relations) and `rules.classification` (sha256
  of the canonical catalog), each truncated to 6 hex characters (as in D32). Stored in memory, not
  recomputed per call (D32).
- **Rationale**: Short, stable, and enough to match a receipt with a Git commit.

## R12. Ceiling

- **Decision**: classes ordered `public < internal < personal < secret`. In this feature the caller ceiling
  comes from config for stdio (D24). A field above the ceiling is dropped with `ceiling.exceeded`.
  `personal` is never raw regardless of ceiling (R2). Tokens in spec 005 supply the ceiling for HTTP.
- **Rationale**: D24. Ceiling is an additional gate, not a replacement for classification.

## R13. gate:check

- **Decision**: `gate:check` loads every contract and the catalog and fails with exit code 1 on: an
  unclassified column referenced by a contract, a `secret` or unclassified field in a contract, a relation
  depth above one, a configured limit above its cap, a missing canary value while canaries are enabled,
  and a missing `ext-intl`.
- **Rationale**: FR-025, SC-005.
