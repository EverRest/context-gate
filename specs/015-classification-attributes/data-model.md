# Data Model: Classification Declared on the Model

No table, no cache key. In-memory structures built once per process.

## Classify (attribute, host-facing)

| Field | Type | Rule |
|---|---|---|
| `columns` | `array<string, string\|Ceiling>` | column name → class; string must be `public`, `internal`, `personal`, `secret` |

Target: class. One per class. Read by reflection, never by query.

## Model declaration

| Field | Type |
|---|---|
| model | `class-string<Model>` (the class carrying the attribute, or the child it applies to) |
| table | string (`getTable()`) |
| column | string |
| class | `Ceiling` |
| declared on | `class-string` (parent class for an inherited declaration) |

## Configuration entry (unchanged)

`context-gate.classification.<table>.<column>` = class string.

## Effective catalog

| Field | Type | Notes |
|---|---|---|
| `map` | `array<string, array<string, Ceiling>>` | table → column → stricter of all sources; keys sorted |
| `catalog` | `Catalog` | built from `map`; what every caller uses |
| `conflicts` | `list<Conflict>` | for `gate:check` only |
| `stale` | `list<ModelDeclaration>` | declared columns missing from the table; computed lazily by the check |

Built once per process (container singleton). An unknown class throws `InvalidArgumentException` naming model and
column.

## Conflict

| kind | subject (gate:check) | names |
|---|---|---|
| `config_widens` | `classification.<table>.<column>` | the model, the model class, the config class |
| `models_disagree` | `classification.<table>.<column>` | both models, both classes |
| `child_widens` | `classification.<table>.<column>` | child and parent |

## Unclassified column (CI mode)

`{model, table, column}`. Never a value, type or suggestion.

## Unreadable model (CI mode)

`{model}`: table missing or schema read failed (exception class to stderr only).

## Class order

`public` < `internal` < `personal` < `secret`; `Ceiling::stricter()` returns the higher.
