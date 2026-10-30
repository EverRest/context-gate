# Research: Laravel Sources

## R1. Source interface and tools

- **Decision**: `Source` interface with `name(): string` and `read(SourceRequest $request): SourceResult`.
  Each source is exposed as one tool (`routes.list`, `schema.describe`, `config.keys`, `docs.read`) that
  declares a `ContextContract` (spec 002) and returns its result through the firewall pipeline.
- **Rationale**: One place for common behaviour (refusal codes, truncation, receipts); the firewall applies
  to sources exactly as to any other tool (FR-014).
- **Alternatives considered**: sources writing straight to the response (bypasses the firewall; rejected).

## R2. Routes

- **Decision**: read `Route::getRoutes()` (framework router). For each route: method, uri, name, action
  (controller class and method), middleware names (not parameters yet), and FormRequest rule names from the
  controller method's type-hinted `FormRequest` parameter, read through reflection of the class's `rules()`
  method keys only (not values).
- **Excluded by default**: `telescope/*`, `horizon/*`, `_ignition/*`, `_debugbar/*`, `pulse/*`, `sanctum/*`
  (D28). Config `routes.exclude` adds patterns; it cannot remove a default (FR-002).
- **Secrets in paths**: a segment of 20 or more characters from `[A-Za-z0-9_-]` is replaced with `<SECRET>`
  (FR-003). Middleware parameters pass the `Redactor` from spec 002.
- **Verification point** (T-spike): confirm that reflecting the FormRequest class without constructing it
  reads `rules()` safely. If a class needs constructor injection, the rule list is left empty and the route is
  still listed (edge case).

## R3. Eloquent schema

- **Decision**: for each model in `config('context-gate.sources.eloquent.models')`, read the table name, the
  columns through `Schema::getColumns($table)`, indexes through `Schema::getIndexes($table)`, casts through
  `getCasts()`, and relations by reflecting public methods that return `Illuminate\Database\Eloquent\Relations\Relation`
  (no method is invoked with side effects; relation name, related model and type only).
- **Secret columns**: classified `secret` in the catalog (spec 002) are removed. The model entry carries
  `hidden_columns: N` (FR-005, D27).
- **No rows** (FR-006): the code never calls a query builder on the model. Tests assert zero query log entries.
- **Verification point**: `Schema::getColumns()` and `getIndexes()` exist in Laravel 12. If the database is
  unreachable, the source returns `schema.missing` (spec, assumptions).

## R4. Configuration keys

- **Decision**: walk `config()->all()` recursively. For each leaf: dotted key, PHP type name. For environment
  variable names, read the `env()` calls only through an explicit allowlist written by the operator in
  `config/context-gate.php` (`sources.config.env_map`), because `env()` calls are not discoverable at runtime.
  The map is checked with `SensitiveNames` (FR-008): if either the key or the variable name contains a
  sensitive word (case-insensitive), the variable is shown as `env:<hidden>`.
- **Values** are never read into the response (FR-007). The walk stores only the type of each leaf.
- **Rationale**: D30. Runtime discovery of `env()` calls is not possible without parsing source code; an
  explicit map keeps the rule auditable.
- **Alternatives considered**: parsing `config/*.php` source for `env(` (fragile; rejected).

## R5. Documentation

- **Decision**: `DocsPathPolicy` decides access. Steps for a request path:
  1. join with the application root; `realpath()` the result (resolves symlinks, FR-011);
  2. refuse if the real path is outside the root;
  3. refuse if any segment matches the code denylist: `.env*`, `*.key`, `*.pem`, `auth.json`, `storage/`,
     `vendor/`, `.git/`, `node_modules/`, any dotfile (FR-010). The denylist is a constant in code; no
     configuration reads it;
  4. require the real path to be under one of the allowlisted roots from config
     (`sources.docs.paths`, default `docs/`, `README.md`, `CHANGELOG.md`) (FR-009);
  5. require the extension to be one of `.md .txt .rst .yaml .yml .json` (FR-012).
- Any failed step returns `source.refused` to the caller and records the step name in the receipt, never the
  path (FR-017).
- **Text** is read with a size cap equal to the per-call limit (spec 002); truncation at a line boundary.
  The text then goes through the spec 002 normalizer, redactor, canary check and quarantine (FR-013).
- **Verification point**: case-insensitive file systems (macOS default). Matching uses the real path with
  the case the file system returns; this is tested on macOS and Linux (edge case).

## R6. Shared sensitive-name rule

- **Decision**: `SensitiveNames::matches(string ...$names): bool` returns true when any name contains one of
  `secret`, `key`, `token`, `password`, `dsn`, `private`, `credentials` (case-insensitive substring). Used by
  ConfigSource (R4) and RoutesSource (R2) for path segments named like credentials.
- **Rationale**: one list, one place to change (roadmap D30).

## R7. Failure codes

- **Decision**: `source.missing` (file gone or not readable), `schema.missing` (table or database unavailable),
  `source.refused` (policy step failed). Added to the reason-code list from spec 001. The caller sees the code
  and a reference id, never a stack trace (FR-015).

## R8. Read-only guarantee

- **Decision**: sources receive no write capability: no model instances are created, no request is sent to
  the database beyond schema introspection, and the file reads are opened read-only. Tests assert that the
  query log is empty after a schema request and the file modification time is unchanged after a docs request
  (FR-016).
