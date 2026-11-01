# Data Model: Row Scopes per Caller

No tables, no cache keys. Scope values live only in memory for one call (FR-008).

## Scope (declaration, `src/Scope/Scope.php`)

Returned by `ContextContract::scope()`.

| Form | Meaning |
|---|---|
| `null` (default) | not declared: `contract.scope_undeclared`, `config.invalid`, `gate:check` fails (FR-002) |
| `Scope::none()` | explicitly unscoped: no rows are checked |
| `Scope::by(['federation' => 'federation_id', ...])` | scope name => column of the contract's model table |

| Field | Type | Rule |
|---|---|---|
| names | `array<string, string>` | 1 to 4 entries; name matches `/^[a-z][a-z0-9_]{0,63}$/`; column is in the catalog for the table (FR-003) |
| unscoped | bool | true only for `Scope::none()` |

Canonical form for the rule hash: `{"federation": "federation_id"}`, key-sorted, or `"none"`.

## ScopeGrant (`src/Scope/ScopeGrant.php`)

Returned by the host resolver per scope name.

| Constructor | Fields | Rule |
|---|---|---|
| `ScopeGrant::values(list<int\|string>)` | mode `restricted`, values | 1 to 100 values (`Caps::SCOPE_VALUES`, FR-007); each an int or a non-empty string of at most 191 characters; an empty list throws |
| `ScopeGrant::all()` | mode `all` | explicit, per name (FR-015) |

## CallScope (`src/Scope/CallScope.php`)

One per call, built by `ScopeResolution`, bound as `context-gate.scope` and unbound when the call ends.

| Field | Type | Notes |
|---|---|---|
| grants | `array<string, ScopeGrant>` | names in force => grant |
| removed | `array<string, int>` | rows removed per name by `ScopeFilter` and `Relations` |

| Method | Purpose |
|---|---|
| `current(): ?self` | for handlers (FR-010) and `ResponseBuilder::receipt` |
| `admits(array $row, Scope $scope): bool` | the one row predicate, used by `ScopeFilter`, `Relations` and `ScopeStep` |
| `apply(Builder $query, Scope $scope): void` | `whereIn` per restricted name, for specs 012 and 013 and handlers |
| `bindingKey(): string` | sha256 of the canonical grants; server-side only (spec 012 cursors); never in a receipt |
| `summary(): list<array{name, mode, removed}>` | the receipt `scope` field |

## Resolution outcome (`src/Scope/ScopeResolution.php`)

| Outcome | Reason code | Handler runs |
|---|---|---|
| no names in force | none | yes |
| every name granted, valid, in time | none | yes |
| no resolver bound (default `NoScopes`) and a name in force | `scope.unresolved` | no |
| throws, malformed, missing name, over 100 values, over the time limit | `scope.unresolved` | no |
| pseudonym argument resolves to a record outside the scope | `scope.excluded` | no |

## Receipt additions (`src/Server/Receipt.php`)

| Field | Type | When present |
|---|---|---|
| `scope` | list of `{name: string, mode: "restricted" \| "all" \| "unresolved", removed: int}` | a scope name is in force for the call |
| `dropped[].reason` = `scope.excluded` | string | a related record was outside the scope |

No scope value and no hash of one appears in a receipt (FR-016, SC-003).

## Reason codes (`src/Server/ReasonCode.php`)

| Code | Decision | Model sees |
|---|---|---|
| `scope.unresolved` | refused | `Request refused.` |
| `scope.excluded` | refused (pseudonym argument) or a dropped reason (relation) | `Request refused.` (identical to `pseudonym.unknown`) |

## Settings (`config/context-gate.php`, `SettingsSchema`, `Profiles`, `CapsValidator`)

| Key | Type | Strict | Balanced (cap) | Rule |
|---|---|---|---|---|
| `scopes.timeout_ms` | int or null | 500 | 2000 (`Caps::SCOPE_TIMEOUT_MS`) | null takes the profile value; above the cap fails `gate:check` |

The 100-value cap (`Caps::SCOPE_VALUES`) and the 4-name cap (`Caps::SCOPE_NAMES`) are code only (spec Out of Scope: no
configuration raises the value-list cap).
