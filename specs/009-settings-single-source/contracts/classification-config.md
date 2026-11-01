# Contract: `classification` in `config/context-gate.php`

The host-facing interface of this feature. The settings path itself is internal (FR-014).

## Shape

```php
'classification' => [
    // host tables: table => column => class
    '<table>' => ['<column>' => 'public'|'internal'|'personal'|'secret', ...],

    // built-in source tables: only hiding is allowed
    '<source_table>' => false,                         // hide the table
    '<source_table>' => ['<column>' => false, ...],    // hide columns
    '<source_table>' => ['<column>' => '<its built-in class>'],   // accepted, no change
],
```

Built-in tables (defined in code, all columns `internal`):

| Table | Columns |
|---|---|
| `source_routes` | `method`, `uri`, `name`, `controller`, `rule_names`, `middleware` |
| `source_config_keys` | `key`, `type`, `env` |
| `source_schema_items` | `model`, `table`, `kind`, `name`, `type`, `class`, `detail` |
| `source_docs` | `source`, `text`, `truncated` |
| `source_openapi_endpoints` | `version`, `method`, `path`, `parameters`, `request_schema`, `examples`, `response_schemas`, `security` |
| `source_runbook_rows` | `step`, `status`, `text` |

The published file ships `'classification' => []`.

## `gate:check` messages (exit 1, key named, no value)

| Case | Subject | Message |
|---|---|---|
| unknown `source_*` table | `context-gate.classification.<table>` | `the source_ prefix is reserved for the package` |
| built-in column with another class | `context-gate.classification.<table>.<column>` | `a built-in source column can only be hidden (false)` |
| new column on a built-in table | `context-gate.classification.<table>.<column>` | `a built-in source table cannot get new columns` |
| host column class not in the four classes | `context-gate.classification.<table>.<column>` | `must be one of: public, internal, personal, secret` |
| `classification` not a map | `context-gate.classification` | `must be a section of settings` |

Under the same document every tool call is refused with `config.invalid` and `tools/list` is empty.

## Receipts

`rules.classification` is the hash of the merged map, effective limits and the call's ceiling (research R4). It is
identical for a document that repeats a built-in table exactly and one that omits it.
