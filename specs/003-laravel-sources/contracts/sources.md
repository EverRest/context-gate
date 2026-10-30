# Contract: Laravel sources

Each source is a tool. Responses pass the spec 002 firewall pipeline. Errors use the reason-code model
from spec 001, extended by R7.

## routes.list

Arguments: `{"prefix": "orders"}` (optional, plain string, max 64 characters).

Result: `{"routes": [RouteEntry, ...]}`. Excluded routes are absent; no hint of their existence.

## schema.describe

Arguments: `{"model": "App\\Models\\User"}`. The model must appear in the configured list; otherwise
`policy.denied`.

Result: `{"model": "...", "table": "users", "columns": [...], "hidden_columns": 1, "indexes": [...],
"casts": {...}, "relations": [...]}`.

## config.keys

Arguments: `{"prefix": "database"}` (optional).

Result: `{"keys": [{"key": "database.connections.mysql.host", "type": "string", "env": "env:DB_HOST"}]}`.

## docs.read

Arguments: `{"path": "docs/architecture.md"}`. Path is relative to the application root.

Result: `{"source": "docs/architecture.md", "text": {...wrapped...}, "truncated": false}`.

Refusal: `Request refused.` with receipt reason `source.refused` and step name (R5). No path in the response.

## Receipt additions

- `source`: tool name.
- `refusal_step`: step name, only for docs refusals.
- `hidden_columns`: integer, for schema calls.
