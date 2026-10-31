# Contract: Laravel sources

Each source is a tool. Responses pass the spec 002 firewall pipeline. Errors use the reason-code model
from spec 001, extended by R7.

## routes.list

Arguments: `{"prefix": "orders"}` (optional, plain string, max 64 characters).

Result: a list of rows, one per route. Excluded routes are absent; no hint of their existence. Each row is scalar:
`{"method": "POST", "uri": "api/orders", "name": "orders.store", "controller": "App\\Http\\Controllers\\OrderController@store",
"rule_names": "customer_id, total", "middleware": "api, throttle"}`. `rule_names` and `middleware` are comma-joined
strings, because the pipeline passes scalar values only. `name` and `controller` may be null.
Pseudo-table: `source_routes`.

## schema.describe

Arguments: `{"model": "App\\Models\\User"}`. The model must appear in the configured list; otherwise
`policy.denied`.

Result: a list of rows, one per item of the model. Each row is scalar:
`{"model": "App\\Models\\User", "table": "users", "kind": "column", "name": "email", "type": "varchar", "class": "personal", "detail": ""}`.
`kind` is one of `column` (`type` is the database type, `class` the catalog class), `index` (`type` is `unique` or
`index`, `detail` the comma-joined columns), `cast` (`type` is the cast), `relation` (`type` is the relation class
name, `detail` the related model) and `hidden_columns` (one row, `detail` is the count of secret columns removed).
Pseudo-table: `source_schema_items`.

## config.keys

Arguments: `{"prefix": "database"}` (optional).

Result: a list of rows, one per leaf key: `{"key": "database.connections.mysql.host", "type": "string", "env": "env:DB_HOST"}`.
`env` is null when no env name is mapped, and `env:<hidden>` when the name is sensitive. Pseudo-table: `source_config_keys`.

## docs.read

Arguments: `{"path": "docs/architecture.md"}`. Path is relative to the application root.

Result: one row: `{"source": "docs/architecture.md", "text": "...", "truncated": false}`. The text is wrapped as
untrusted by the firewall quarantine stage, like any text field. Injection flags are reported as the pipeline
reports them for every tool: in the receipt `flags` and in the response `structuredContent.flags`; the flag names only,
never the matched text. Pseudo-table: `source_docs`.

Refusal: `Request refused.` with receipt reason `source.refused` and step name (R5). No path in the response.
The source tool raises `SourceFailure` with the reason and step; GateCallTool writes the receipt. A missing file or
table (`source.missing`, `schema.missing`) returns `Request failed. Reference: ...` with its own reason.
Default `sources.docs.paths` is empty, so every path is refused until the operator lists one (`allowlist` step).

## Receipt additions

- `source`: tool name.
- `refusal_step`: step name, only for source refusals. Written only when given.
- Pseudo-tables: each source's rows are classified in the catalog under its pseudo-table (for example
  `source_routes`). Every column of those tables is `internal` in the default `context-gate.classification`; an
  operator's own catalog must keep these entries, or the column is unclassified and dropped (FR-014).
