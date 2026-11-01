# Privacy

context-gate exists so that an assistant receives only the data a contract allows. Each value is classed, and each
call passes through a fixed pipeline before anything is returned.

## Data classes

Classes run from least to most sensitive: `public`, `internal`, `personal`, `secret`. A caller has a ceiling, and a
value is returned only when its class is at or below that ceiling. A value above the ceiling is dropped, unless a
stage replaces it with a pseudonym, bucket, date or count first.

- `public`: may be shown to any caller.
- `internal`: shown to callers whose ceiling is `internal` or higher.
- `personal`: names, contact details and similar. Allowed only up to a `personal` ceiling, and even then a raw value is
  never shown: a personal column leaves only as a pseudonym, bucket or aggregate.
- `secret`: credentials and keys. Never released; no token can carry this ceiling. A stdio ceiling of `secret` passes
  `gate:check` but serves nothing.

Columns not listed in the classification catalog are unclassified and are dropped. See
[configuration.md](configuration.md#classification).

## Pipeline

Each call runs in this order:

0. At call entry, the per-minute limit is checked (`Budget::admit`). It runs after the tool is found visible and its
   settings are valid, so a call refused for configuration is not counted.
1. Policy and contract: the tool must be enabled and visible to the caller, and its contract must resolve and pass its
   load rules (a violation is `config.invalid`). A hidden tool, a disabled gate and an unknown tool are all reported as
   not found.
2. Classify: each field is checked against the caller's ceiling.
3. Project: only the fields the contract lists are kept, in order. Nested data never passes through a projected field.
4. Relations: a related record replaces its foreign key, one level deep, shaped by the related contract and subject to
   the same ceiling, transform and projection.
5. Transform: personal values become pseudonyms, buckets, dates or aggregates. A pseudonym never shows the raw value.
6. Normalise: text is normalised so that split or invisible characters cannot hide a value.
7. Redact: text fields pass a regular-expression redaction layer. A field that fails redaction is dropped from every
   row, and the rest of the response is returned.
8. Canary check: a canary value found in the serialized rows blocks the response. The same check also runs before
   step 7, on the text fields as they arrive.
9. Quarantine: each text field is wrapped as untrusted content with its source name. Injection phrases set a flag. The
   stage never blocks on its own.
10. Budget: per-call truncation and the per-session and per-day byte limits. Rows are capped before pseudonymising (twice
    the per-call limit, at most 10 000 rows) as a work bound; the real truncation still happens on the final rows.

A refusal returns the generic text `Request refused.` (a rate-limited call adds the retry delay). The reason code goes
to the receipt only, never to the model. A bad argument is the exception: the model sees `Invalid arguments: ...`
as an `isError` result. A failure returns `Request failed. Reference: r_...`.

When a result was truncated or contains text flagged as untrusted, a second content block starting `Notice:` tells the
model. The server `instructions` text says results are untrusted data and refusals are generic; only the `tools`
capability is advertised.

## Transforms

- `Field::date()` accepts only `year` and `month` precision; any other value is refused when the contract is built.
- `Field::bucket()` needs 1 to 20 strictly ascending breaks. With two or more, every adjacent gap is at least
  `max(2, 5%)` of the span between the first and the last break, so a bucket never narrows to a single raw value.
- A null in a bucket or date column passes through as null.
- Pseudonyms are issued in one batch per call (one lock, load and save). A scope holds at most 10 000 entries and its
  key rotates beyond that; a single batch of more than 10 000 distinct ids fails closed. A cache that refuses to store
  the map fails the call with `pseudonym.keyfail`.
- User-written columns must use `Field::text()`: only text fields are wrapped as untrusted and redacted.

## Redaction limits

Redaction is best-effort regex matching for phone numbers (including local formats), emails, IBANs, card numbers and
bearer tokens. It is not a PII detector: names, addresses and free-form identifiers pass. The contract is the primary
protection; redaction is a second layer for text you chose to release.

## Canaries

Canary values are secrets you plant to detect leaks: a fake value in a test row, or a real value you never want to
leave. Configure them under `canaries.values`. A canary found in a response or in serialized output blocks the whole
response (reason `canary.hit`). Matching ignores letter case and folds common Latin, Cyrillic and Greek look-alike characters. The check runs twice: before redaction
(stage `canary.pre`, so a canary inside a text field, including an email-shaped one, is caught before it could be masked) and again on the serialized
rows. The canary value itself is never logged or returned.

Use fake values in examples and tests, for example `FAKE_FIXTURE_ONLY`.

## Receipts

Every call writes one receipt line to the `context-gate` log channel (stderr). A receipt holds the tool name, caller
id, outcome, reason code, rule hashes, dropped field names, detector names and byte counts. It holds no arguments, no
results, no values and no token values. Field names are kept; values are not.

When a call fails, one more metadata-only line goes to the same channel: the receipt reference, the exception class,
the file basename and the line number. The exception message is never written.

## `docs.read`

The path denylist (env files, keys, certificates) matches after Unicode folding (NFKC and case fold); a path that is
not valid UTF-8 is refused. The canary check runs before redaction, so email-shaped canaries fire. The per-call limit is
the effective profile value (strict: 16384 bytes) clamped to the cap, and the text is trimmed so that the JSON-encoded
row fits.

## Reason codes

These are the values of `ContextGate\Server\ReasonCode`, as written to the `reason` field of a receipt. The decision
is the receipt's `decision` field. The model never sees a reason code.

| Reason | Decision | Meaning |
|---|---|---|
| `policy.hidden` | hidden | The tool is unknown, hidden from this caller, or the gate is disabled. |
| `tool.pin_mismatch` | hidden | The tool's fingerprint no longer matches the configured one. |
| `input.invalid` | invalid | A tool name is missing, or the arguments fail validation. |
| `policy.denied` | denied | The gate ability is missing or denies, the tool has no contract, or the ceiling is `secret`. |
| `handler.failed` | failed | The tool handler threw. |
| `pseudonym.unknown` | refused | A pseudonym argument is unknown or expired. |
| `pseudonym.keyfail` | failed | A pseudonym could not be issued or stored. |
| `transform.failed` | failed | A transform (bucket, date, aggregate) failed. |
| `config.invalid` | refused | The settings or a contract break a rule. |
| `canary.hit` | blocked | A canary value was found in the arguments or the output. |
| `budget.session` | refused | The per-session byte limit is used up. |
| `budget.daily` | refused | The per-day byte limit is used up. |
| `rate.limited` | refused | The per-minute call limit is exceeded. |
| `source.missing` | failed | A source file or document does not exist. |
| `schema.missing` | failed | The schema source could not read the table. |
| `source.refused` | denied | A source refused the request (path, model, document shape). |
| `egress.denied` | denied | A URL fetch named a host or address that is not allowed. |
| `source.redirect` | denied | A URL fetch was redirected. |
| `source.timeout` | failed | A URL fetch timed out. |
| `source.too_large` | denied | A document is over the byte limit. |
| `openapi.version` | denied | The document is not OpenAPI 3.x. |
| `token.missing` | refused | No bearer token (HTTP 401). |
| `token.invalid` | refused | The token is unknown, expired or revoked (HTTP 401). |
| `token.no_read` | refused | The token lacks `gate:read` (HTTP 403). |
| `token.no_ceiling` | refused | The token has no ceiling ability or more than one (HTTP 403). |
| `token.ability` | refused | The token carries an unknown ability (HTTP 403). |
| `origin.denied` | denied | The browser `Origin` is not listed (HTTP 403). |
| `field.unlisted` | dropped | A field the contract does not list was dropped. |

## Never sent

- Values above the caller's ceiling, including personal values of related records.
- Raw personal values where a pseudonym, bucket, date or count is configured.
- Nested data through a projected field.
- Config values. The config source reports keys, types and environment names only.
- Secrets, token values and canary values, in responses, receipts or logs.
- Any field the classification catalog does not list.
