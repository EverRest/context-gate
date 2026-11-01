# Data Model: MCP Resources and Prompts

No tables, no new cache keys: resource reads and prompt gets use the existing budget keys (research R5).

## Code caps (`src/Pipeline/Caps.php`)

| Constant | Value | Meaning |
|---|---|---|
| `RESOURCE_PAGE` | 1000 | most resources per `resources/list` page (default too) |
| `RESOURCE_LIST` | 10000 | most resources enumerated per list walk; later files are not listed |
| `PROMPT_ARGUMENT` | 1000 | highest `maxLength` of any prompt argument |

## Setting keys

| Key | Type | Default | Rule |
|---|---|---|---|
| `resources.docs.enabled`, `resources.schema.enabled` | bool | false | |
| `resources.<entry>.gate` | string or null | null | null = denied |
| `resources.<entry>.fingerprint` | string or null | null | must equal `Fingerprint::ofResource(entry)` |
| `resources.<other>` | — | — | violation: only `docs` and `schema` exist |
| `prompts.<name>.enabled` | bool | false | |
| `prompts.<name>.class` | class-string of `ContextGate\Server\Prompt` | — | required |
| `prompts.<name>.gate`, `.fingerprint` | string or null | null | |
| `callers.<id>.resources` | list of entry names | `[]` | each must exist in `resources` |
| `callers.<id>.prompts` | list of prompt names | `[]` | each must exist in `prompts` |
| `resources_page_size` | int or null | null (= 1000) | 1 ≤ v ≤ `RESOURCE_PAGE` |

## ResourceEntry (fixed in code, `src/Server/Resources/ResourceRegistry.php`)

| Field | `docs` | `schema` |
|---|---|---|
| kind | document | schema |
| uriTemplate | `gate://docs/{path}` | `gate://schema/{model}` |
| description | fixed text | fixed text |
| mimeType | `application/json` | `application/json` |
| tool reused | `DocsReadTool` + `DocsContract` | `SchemaDescribeTool` + `SchemaItemsContract` |
| items from | `sources.docs.paths` (files, policy-filtered) | `sources.eloquent.models` |
| ability name | `resource:docs` | `resource:schema` |

State per caller: `visible` = gate enabled ∧ entry enabled ∧ pinned ∧ listed in `callers.<id>.resources`.

## Prompt (host class extending `ContextGate\Server\Prompt`, `@api`)

| Field | Type | Rule |
|---|---|---|
| name | string | `^[A-Za-z0-9_.-]{1,128}$`, unique among prompts |
| description | string | |
| schema | JSON schema | `type: object`; every property `type: string` with `maxLength` ≤ `PROMPT_ARGUMENT`; `required` allowed |
| template | string | no canary; unchanged by the redactor; placeholders `{{name}}` for schema properties only |
| tools | list of tool names | each configured in `tools` |

State: `valid` (load check), `pinned`, and per caller `visible` = gate enabled ∧ enabled ∧ valid ∧ pinned ∧ listed in
`callers.<id>.prompts` ∧ every tool in `tools` visible to the caller.

## Fingerprint input

| Kind | Members (sorted keys, sha256) |
|---|---|
| resource | `kind`, `name`, `description`, `mimeType`, `uriTemplate` |
| prompt | `kind`, `name`, `description`, `schema`, `template`, `tools` |

## Reason codes (`src/Server/ReasonCode.php`)

| Value | Decision |
|---|---|
| `resource.pin_mismatch` | hidden |
| `prompt.pin_mismatch` | hidden |

Hidden, unknown and refused resources/prompts otherwise reuse `policy.hidden`, `policy.denied`, `input.invalid`,
`canary.hit`, `config.invalid`, `source.refused`, `source.missing`, `schema.missing`, `budget.*`, `rate.limited`.

## Receipt changes (`src/Server/Receipt.php`)

| Field | Type | Present | Values |
|---|---|---|---|
| `kind` | string | always | `tool`, `resource`, `prompt` |
| `tool` | string or null | as today | tool name, resource entry name or prompt name |
| `uri_hash` | string | resource reads with a parsed URI | first 16 hex of sha256(URI) |

Never: the path, the model class, prompt arguments, resource content.

## Capabilities per caller

| Caller sees | `capabilities` |
|---|---|
| nothing extra | `{"tools": {"listChanged": false}}` (today) |
| ≥ 1 resource | + `"resources": {"listChanged": false}` |
| ≥ 1 prompt | + `"prompts": {"listChanged": false}` |
