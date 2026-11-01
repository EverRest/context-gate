# Data Model: One Path to Settings

No tables. All entities are in-process values, built once per process.

## Settings document

The `context-gate` config array after `mergeConfigFrom` and the spec 007 overlay (`ConfigOverlay`). Read only by
`src/Settings/`.

## ActiveSettings (typed settings)

| Field | Type | Source |
|---|---|---|
| `violations` | `list<array{key, message}>` | `SettingsValidator` (schema + caps + profile + classification + cross-checks) |
| `document` | `array<string, mixed>` | settings document |
| `environment` | `string` | `app.env`, empty or unset = `production` |
| `limits` | `array<string, int\|float>` | `EffectiveSettings::for(document, env)` |
| `ceiling` | `Classification\Ceiling` | `firewall.ceiling`, default `internal` |
| `classification` | `array<string, array<string, Ceiling>>` | `ClassificationMerge::effective()` |
| `tools` | `array<string, ToolEntry>` | `tools` |
| other getters | see research R2 | |

Lifecycle: constructed on first resolve of the container singleton; immutable afterwards; one per PHP-FPM request,
Octane worker, or stdio server process. Invalid state: `valid()` false, typed getters throw `InvalidSettings` except
`channel()` (research R2).

## ToolEntry

| Field | Type | Rule |
|---|---|---|
| `name` | `string` | key in `tools` |
| `class` | `class-string<Tool>` | must extend `Tool` |
| `enabled` | `bool` | |
| `gate` | `?string` | host Gate ability |
| `fingerprint` | `?string` | |
| `contract` | `?class-string<ContextContract>` | |

## BuiltinClassification

Constant map of six `source_*` tables (contracts/classification-config.md). Not configurable.

## Effective classification

`built-in − hidden tables − hidden columns + host tables`. Validation rules: research R3. A repeated built-in entry
equals no entry.

## Rule hashes

| Hash | Input | Computed |
|---|---|---|
| `tool` | key-sorted tool name => description | once per process |
| `contract` | key-sorted tool => contract class and ordered fields | once per process |
| `classification` | merged classification, effective limits, call ceiling | once per distinct ceiling per process |

Spec 010 adds the effective role definition to the `classification` input.
