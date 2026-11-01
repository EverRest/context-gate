# Research: Row Scopes per Caller

Code read at HEAD `0e5d7f8` (spec 018 runbooks merged; spec 010 roles not yet implemented: `Caller::current()` still
reads `callers`). Every decision below names the class it changes.

## R1. How a contract declares a scope

**Decision**: `ContextContract` gains a non-abstract method `scope(): ?Scope` that returns `null` by default. A
contract returns `Scope::by(['federation' => 'federation_id'])` (scope name => column of its model) or
`Scope::none()` (explicitly unscoped). `null` means "not declared" and is a load violation
(`contract.scope_undeclared`) reported by `ContractValidator`, so it reaches `ContractGuard` (per call:
`config.invalid`) and `ContractCheck` (`gate:check` fails and names the contract) exactly like the other load rules
(FR-002).

**Rationale**: An abstract method would turn a missing declaration into a PHP fatal error at class load, not a named
`gate:check` failure (FR-002 asks for the latter). A default that returns `null` keeps existing contracts loadable
until the check names them. `Scope` is a small value object (`src/Scope/Scope.php`) so the validator, the rule hash and
the filter read one shape.

**Validation rules** (in `ContractValidator::validate`, recursive through relations as today):
- scope names match `/^[a-z][a-z0-9_]{0,63}$/`; 1 to 4 names per contract (two are enough for the edge case
  "federation and event"; 4 leaves room, a cap keeps the resolver call small);
- each column exists in the catalog for the model's table (FR-003); its class is not checked against the ceiling
  (scope check only), and is still refused as a projected field if the class forbids it;
- a secret column may be a scope column (it is never projected; the check only compares it). Accepted because FR-003
  says the class "does not limit the scope check".

**Alternatives rejected**: config key `contracts.<class>.scope` (splits a contract's rules across two files and the
contract hash would not see it); a PHP attribute on the class (spec 015 owns attributes; it is not merged).

## R2. The host scope resolver (new extension interface)

**Decision**: `src/Contracts/ResolvesScopes.php`, following the pattern of `AuthorizesTools` and `IssuesPseudonyms`:

```php
interface ResolvesScopes
{
    /**
     * @param list<string> $names the scope names in force for this call (contract and related contracts)
     * @return array<string, ScopeGrant> name => grant; a missing name means "not resolved"
     */
    public function resolve(Caller $caller, array $names): array;
}
```

`ScopeGrant` (`src/Scope/ScopeGrant.php`) has two constructors: `ScopeGrant::values(list<int|string>)` and
`ScopeGrant::all()`. Empty lists, wildcards, `null` and `'*'` are not representable as "all" (FR-015);
`values([])` throws in the constructor, so a host cannot build one by accident.

The default binding is `src/Scope/NoScopes.php`, which returns `[]`. With it, every scoped contract is refused
(`scope.unresolved`) and unscoped contracts never call the resolver. This is the "resolver not installed" case of
FR-006: the gate fails closed until the host binds its own implementation in its service provider.

**Fail-closed semantics** (`src/Scope/ScopeResolution.php`, the only caller of the interface):

| Resolver outcome | Result |
|---|---|
| throws anything | `scope.unresolved`; the exception goes to `Failure::hold` (metadata note only, constitution VII) |
| returns a non-array, or an entry that is not a `ScopeGrant` | `scope.unresolved` |
| a needed name missing from the result | `scope.unresolved` |
| a `values` grant with more than 100 values (FR-007, `Caps::SCOPE_VALUES`) | `scope.unresolved` |
| a value that is not an int or a non-empty string of at most 191 chars | `scope.unresolved` |
| took longer than the time limit (R3) | `scope.unresolved`, result discarded |
| every needed name has a valid grant | the `CallScope` for this call |

Extra names the host returns are ignored. The resolver is called once per call, only when at least one scope name is
in force (FR-008: no cache across calls).

**Rationale**: The interface takes the `Caller`, never arguments (FR-004). Over stdio the `Caller` id is the OS user
name; spec 010 adds the role to `Caller`, so the host can map role and token owner to values without the package
storing them.

## R3. The resolution time limit

**Decision**: `scopes.timeout_ms`, default 500 (strict) and code cap `Caps::SCOPE_TIMEOUT_MS` = 2000 (balanced).
`ScopeResolution` measures the call with `hrtime(true)` and discards a result that arrives after the limit.

**Limit of this design (ponytail)**: PHP cannot pre-empt a synchronous call without `pcntl` (not available under
PHP-FPM). A resolver that hangs therefore holds the worker until it returns; the gate then refuses the call. The
guarantee is "never served with a late or missing scope", not "returns within 500 ms". The host's own database and
HTTP client timeouts bound the wait. The documentation (`docs/extending.md`) says so. Upgrade path: a resolver that
queries over HTTP can be given a client timeout below the gate's limit.

**Alternatives rejected**: `pcntl_alarm` (CLI only, interferes with the host's signals); running the resolver in a
child process (cost per call, a new process model).

## R4. Where the scope is resolved in the call path

**Decision**: a new step `src/Server/ScopeStep.php` in `GateCallTool::serve`, after `ArgumentsStep` and before
`FetchStep`:

1. Names in force = the contract's scope names plus those of each related contract (one level). `Scope::none()`
   contributes none. No names: the step does nothing (source tools, reference data).
2. `ScopeResolution` runs. Any failure: receipt `scope.unresolved`, generic refusal (`ResponseBuilder::refused`), the
   handler is not called (FR-005, FR-006, SC-002).
3. Pseudonym arguments (FR-013): for each pseudonym field of the contract present in the arguments (already resolved
   to a record id by `PseudonymArguments`), one query on the model's table reads the scope columns of that record. A
   record outside the scope, missing, or with a null scope value gives the receipt `scope.excluded` and the same
   response as `pseudonym.unknown` (both are `ResponseBuilder::refused($request)` with the default text, so the bytes
   are identical).
4. The `CallScope` is bound as `context-gate.scope` for the rest of the call and unbound in a `finally` in
   `GateCallTool::serve`, so nothing carries over to the next call (FR-008).

**Why after ArgumentsStep**: the canary, schema, pseudonym and policy checks do not need the scope, and a call they
refuse should not call the host resolver. The scope must be known before the handler runs (FR-005).

## R5. Enforcement on hand-written tools: a post-fetch filter stage

**Decision**: a new pipeline stage `src/Pipeline/ScopeFilter.php`, run by `FirewallRunner::firewall` as stage
`scope`, first, before `classify` (FR-009). For each row: for each scope name in force with mode `restricted`, the
row's value in the scope column must be present, scalar, non-null and equal (strict string comparison after casting
ints to strings) to one of the caller's values. Otherwise the row is removed. Mode `all` checks nothing for that name.
A row must pass every name (edge case "two names").

The stage then removes the scope columns that the contract does not list from every row, so they never reach the
`field.unlisted` report and never reach the projection (edge case "scope column not projected"). It counts removed
rows per name (a row failing two names counts once, under the first name that failed, so the total equals the rows
removed).

Ordering consequences, all already in code: the work-bound row cap (`array_slice(..., 10000)` and `fit(.., 2)`) and
the per-call truncation run after `relations`, so after the scope filter (edge case "truncation after scope").

**FR-010 (scope for handlers)**: `CallScope::current(): ?CallScope` reads the binding, so a handler can narrow its own
query (`$scope->apply($query)` helper, R7). This is an optimisation only; the stage still runs (edge case "host already
filtered").

**Stage order**: the stage list is code (constitution VI). Adding `scope` before `classify` changes
`specs/002-context-firewall/contracts/firewall-pipeline.md` and `tests/Contract/PipelineOrderTest.php`; both are
updated in the tasks. The spec 014 FR-009 states the position, so the rule is in a spec first.

## R6. Relations

**Decision**: `Relations::load` reads the related contract's `scope()`. When it is scoped, each loaded record is
checked with the same predicate as `ScopeFilter` (one shared function `CallScope::admits(array $row, Scope $scope)`).
A record outside the scope is removed from `$records` before classification; the parent row's relation becomes `null`
and one entry `{field: <column>, reason: scope.excluded}` is added to `dropped` per relation column (de-duplicated, like
`Projector::drop`). An unscoped related contract (`Scope::none()`) loads as today (US4 scenario 2).

**Why a PHP check, not `whereIn` in SQL**: the loader already reads full rows by key in one query; filtering in PHP
lets the stage tell "missing" (null, as today, no drop entry) from "excluded" (null plus `scope.excluded`), which the
spec requires. The row count is bounded by the parent rows (at most 10 000).

## R7. Query tool and aggregates (specs 012, 013)

**Decision**: `CallScope::apply(Builder $query, Scope $scope): void` adds `whereIn(column, values)` for each
`restricted` name and nothing for `all`. Specs 012 and 013 call it in their query builders before paging and counting
(FR-012). `ScopeFilter` still runs on the page rows (it removes 0 when the SQL was correct; a non-zero count there is
a bug signal and the query tool then issues no cursor, see spec 012 research R8).

`CallScope::bindingKey(): string` returns a sha256 over the canonical scope (names, modes, sorted values). It is
used only in server-side cursor state (spec 012 FR-017) and is never written to a receipt or log (FR-016).

## R8. Receipt fields

**Decision**: `Receipt::write` gains an optional `?array $scope` parameter, written as
`"scope": [{"name": "federation", "mode": "restricted", "removed": 3}]`, only when at least one name is in force (the
optional-field pattern of `dropped`, `simulated`, `status_counts`). Values and their hashes never appear (FR-016,
SC-003). For the query and aggregate tools, `removed` counts only what `ScopeFilter` removed (0 when the SQL applied
the scope); the spec asks for "rows the scope removed", and counting the rows SQL excluded would need a second count
query. `ResponseBuilder::receipt` reads the bound `CallScope` and passes its summary, so every receipt written after
`ScopeStep` carries it, including budget and canary refusals.

A `scope.unresolved` receipt carries `scope` with the names and `mode: "unresolved"`, `removed: 0`, so operators see
which name failed without any value.

## R9. Reason codes

**Decision**: two new `ReasonCode` cases:
- `ScopeUnresolved = 'scope.unresolved'`, decision `refused`, generic `Request refused.` (default arm of
  `ResponseBuilder::forReason`).
- `ScopeExcluded = 'scope.excluded'`, decision `refused` when it refuses a call (pseudonym argument); as a dropped
  reason it appears only inside `dropped` entries (string), like `relation.failed`.

`docs/privacy.md` reason-code table gains both rows.

## R10. Rule hash

**Decision**: `ContextGateServiceProvider::ruleHashes` adds `scope` (names => columns, or `"none"`, or `null`) to each
contract's canonical entry, and to each related contract's (FR-018). A changed scope therefore changes the `contract`
hash, which spec 012 binds into its cursors.

## R11. Dependency on spec 010 and order with 012 and 013

- Spec 010 (caller roles) changes how `Caller` is built and adds the role name. This spec needs only a stable
  `Caller` object to hand to the resolver. Spec 010 FR-012 reserves a `scope attributes` key on roles; with the
  clarified 014 (values resolved by the host on every call), that key stays unused and must stay empty. This plan
  adds nothing to roles.
- **Order**: 010 first (the resolver receives the role-carrying `Caller`), then 014, then 012, then 013. Spec 012 may
  be built before 014 only if no generated tool is enabled on scoped data; this plan makes 014 land first so that 012
  binds cursors to `CallScope::bindingKey()` from the start.
- If 014 must ship before 010, `ResolvesScopes` still works with today's `Caller` (id and tools); nothing in this
  plan reads `callers`.
