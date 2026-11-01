# Data Model: Configuration and Policy Management (MVP)

No tables. Versions are files; everything else is computed per run.

## SecurityProfile

| Value | Meaning |
|---|---|
| `strict` | Default. Lower values from `Profiles` (research R2). |
| `balanced` | Code cap values from `Profiles`. |

Any other value fails validation. No `permissive` value exists.

## EnvironmentProfile (resolution)

| `APP_ENV` | Profile used | Configured `profile` weaker than strict |
|---|---|---|
| `production` | strict (forced) | fails `gate:check` |
| unknown or empty | strict (forced) | fails `gate:check` |
| `local`, `development`, `staging`, `testing` | configured, default strict | allowed |

## Setting keys

| Key | Type | Default | Rule |
|---|---|---|---|
| `profile` | enum `strict`, `balanced` | `strict` | see above |
| `firewall.limits.per_call_bytes` | int or null | null | at most cap 65536 |
| `firewall.limits.per_session_bytes` | int or null | null | at most cap 2097152 |
| `firewall.limits.per_day_bytes` | int or null | null | at most cap 20971520 |
| `firewall.limits.calls_per_minute` | int or null | null | at most cap 300 |
| `sources.openapi.timeout_seconds` | float or null | null | at most cap 5.0 |
| `sources.openapi.max_bytes` | int or null | null | at most cap 2097152 |
| `sources.openapi.access` | enum `read` | `read` | any other value fails (FR-009) |
| `tools.<name>.max_bytes` | int or null | null | at most `firewall.limits.per_call_bytes` effective |
| `tools.<name>.calls_per_minute` | int or null | null | at most `firewall.limits.calls_per_minute` effective |
| `callers.<id>.tools` | list of tool names | `[]` | every name must exist in `tools` |
| `log_level` | enum `debug`, `info`, `notice`, `warning`, `error` | `info` | validated only (research R6) |

Forbidden (fails as unknown, with a named message): `redaction`, any key naming a pipeline stage or a stage order,
and any `access` value other than `read`.

## EffectiveSettings

| Field | Source |
|---|---|
| each limit | explicit value when set and valid, else the profile value |
| `profile` | `EnvironmentProfile` result |

## ConfigVersion (file `versions/<hash>.json` plus `index.json`)

| Field | Type | Rule |
|---|---|---|
| hash | string | sha256 of canonical JSON, full hex |
| document | object | the settings document, no canary values |
| applied_at | string | UTC, `Y-m-d\TH:i:s\Z` |
| actor | string | artisan user or `cli` (no value) |
| previous | string or null | hash of the version active before |

`index.json` holds `{active: <hash>, versions: [ConfigVersion metadata...]}`. The index keeps at most 50 entries.

## AuditLine (channel `context-gate`)

| Field | Type | Rule |
|---|---|---|
| event | enum `config.applied`, `config.rollback` | |
| actor | string | as above |
| at | string | UTC |
| changed_keys | list of keys | names only |
| old_hash, new_hash | string | hash of the document before and after (research R7) |

No value appears in an audit line.

## SimulationResult

| Field | Type | Values |
|---|---|---|
| tool | string | name as given |
| caller | string | caller id |
| decision | enum | `allow`, `hidden`, `invalid`, `denied` (from ReasonCode decision values) |
| reason | string or null | ReasonCode value |
| receipt | string | receipt id, with `simulated: true` in the receipt |
