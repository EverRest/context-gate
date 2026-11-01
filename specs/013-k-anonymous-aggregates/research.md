# Research: k-Anonymous Aggregates

Code read at HEAD `d744441` (after `b59bd05`): `src/Contract/Field.php`, `src/Contract/ContractValidator.php`,
`src/Pipeline/{Transformer,ClassifyCheck,Budget,Caps}.php`, `src/Server/{GateCallTool,PolicyStep,Admission,
ArgumentsStep,FetchStep,FirewallStep,FirewallRunner,ResponseBuilder,Receipt,ReasonCode,ToolRegistry,Fingerprint}.php`,
`src/Settings/*`. Specs 012 and 014 have no plan yet; names this plan takes from them are marked *(provisional, 012)*
or *(provisional, 014)* and are renamed here when those plans land.

## R1. Group-by key rules per class and form (FR-003, FR-004, US3)

**Decision**: the declaration names each group-by key as `column => kind`, with four kinds. The rule is checked at load
(`AggregateDeclaration::violations()`), and any violation is `config.invalid` and a `gate:check` FAIL naming tool and
column.

| Kind | Allowed when | Label in the response | Ceiling applies |
|---|---|---|---|
| `value` | the contract lists the column as `Field::plain()` and its effective class is `public` or `internal` | the stored value (normalised) | yes: above the caller's ceiling is `filter.denied` (FR-019) |
| `bucket` | the contract lists the column as `Field::bucket([...])` (any class but `secret`) | the contract's bucket label (`<10`, `10-19`, `20+`), computed by the same code as `Transformer::bucket` | no (non-raw form, as `ClassifyCheck::NON_RAW`) |
| `year`, `month` | the column's effective class is `public`, `internal` or `personal`; if the contract lists it as `Field::date(p)`, the kind may not be finer than `p` | `Y` or `Y-m`, same code as `Transformer::date` | no (non-raw form) |
| anything else | never | | |

Refused (config.invalid): `pseudonym`, `text`, `relation`, `aggregate` forms; `value` on a `personal` column (raw
personal); any kind on a `secret` column; any column with no effective class; `year`/`month` finer than the
contract's declared date precision; more than `max_group_by` keys in one call is `input.invalid` (FR-004).

**Why `year`/`month` on a personal column does not need the column in the contract**: the clarification allows the
period of a personal date only as a grouping key, and `ContractValidator::PERSONAL_FORMS` (pseudonym, aggregate,
bucket) keeps refusing `Field::date` on a personal column in row contracts. A personal birth date therefore cannot
appear in the contract as a date, so the declaration names the catalog column directly. The class is read from the
effective catalog (spec 015), and the period label is computed on the server and only ever shown with a count of at
least k. `ContractValidator` is not changed (SC-006 is a characterization test).

**Alternatives rejected**: allowing `Field::date` for personal columns in contracts with a flag (widens row
contracts, against the clarification); `Field` objects inside config (config caching with `var_export` needs
`__set_state` on `Field`; strings keep the config cacheable).

## R2. k: floor, default, cap (FR-006)

**Decision**: `Caps::AGG_K_FLOOR = 5`, `Caps::AGG_K_CAP = 1000`. Effective k for a tool =
`max(config aggregates.k ?? 5, declaration k ?? 5)`. Both values must be integers in `[5, 1000]`; outside is a
`SettingsValidator` violation (`aggregates.k`) or a declaration violation (`tools.<name>.aggregate.k`).

**Rationale**: k is a protection where higher is stricter, so "config narrows" means config may only raise it.
Taking the max of the two means neither the global setting nor one declaration can lower the other.

**Not in `Profiles`**: `ProfilesTest` (spec 007) asserts balanced equals the caps and strict is at most balanced;
for k that would make balanced the strictest profile. k and the other aggregate keys are plain settings with code
defaults, outside the profile maps. The floor rule is new in `SettingsValidator` (today it checks caps only).

## R3. Deterministic suppression and the "other" rule (FR-007 to FR-011, FR-013)

**Decision**: one pure function, `Suppression::apply(array $cells, int $k, int $maxGroups): AggregateResult`, with no
clock, no randomness and no store:

1. Input: map of cell label tuple → count, counts ≥ 1 (empty cells never exist: edge case "count of 0").
2. `named` = cells with count ≥ k; `pool` = cells with 1 ≤ count < k.
3. Sort `named` by count descending, then by the canonical JSON of the label tuple ascending (total order; a null
   label sorts as JSON `null`). If `named` has more than `maxGroups` entries, the tail moves into `pool` and the
   result carries `cut = true`.
4. `other = sum(pool)`. If `other ≥ k`: return `other`, and `total = sum(named) + other`. If `other = 0`: no
   `other`, `total = sum(named)`. If `1 ≤ other < k`: no `other`, no `total`, no percentages, `withheld = true`.
5. No group-by: one cell with the empty tuple; the count is returned only when it is 0 or ≥ k (FR-010), else
   `withheld = true`.
6. Percentages (only when requested and `total` is returned): `round(count * 100 / total, 1)` per named group and
   other.

`Notice:` text (FR-011), fixed strings with no label or count: `some groups are below the minimum group size and are
not shown.` (any suppression), `the total is withheld because it would reveal a suppressed group.` (step 4 third
branch), `the result is below the minimum group size.` (step 5), `the number of groups exceeds the cap; smaller groups
are merged into other.` (cut).

**Checks against SC-001**: with `other` shown only when ≥ k and the total shown only when it equals named + other,
no suppressed count can be derived from one response: a cut group is ≥ k and only ever enters `other`, and a single
suppressed group alone can never reach k (edge case "exactly one group is suppressed"). The fixture matrix test
(T014) brute-forces every partition of up to 6 cells with counts 0 to 12 for k = 5 and asserts that no count in
`[1, k-1]` and no difference of two returned numbers equal to a suppressed cell's count appears when that cell is
the only member of the difference. This is a single response property; cross-query differencing is R7.

**Determinism (SC-003)**: the SQL result order is not relied on; step 3 sorts. JSON is encoded with fixed flags.
A success response carries no receipt id or time, so two runs over the same rows are byte-identical.

## R4. Query, fold and work bound

**Decision**: one grouped SQL query per call on the contract model's own query builder
(`(new $model)->newQuery()`, so host global scopes such as soft deletes apply), with the scope (R6) and the filters
(R5) as `where` clauses, `GROUP BY` the raw group-by columns and `COUNT(*)`. Bucket and period labels are computed in
PHP by folding raw groups into labelled cells (`AggregateQuery::fold`). The raw rows are streamed with `cursor()`.

**Rationale**: `CASE` expressions for buckets are portable, but period extraction is not (`strftime` on SQLite,
`DATE_FORMAT` on MySQL, `to_char` on PostgreSQL). Folding in PHP reuses the exact label code of the `Transformer`, so
a bucket label in an aggregate equals the same row's label in a row contract. Raw values stay on the server.

**Work bound**: `Caps::AGG_SCAN_CELLS = 10000` folded cells (the same ceiling as the firewall's row work bound in
`FirewallRunner`). Above it the call fails closed with `transform.failed` (failed, with reference). ponytail: a
`value` key on a high-cardinality column hits this bound; upgrade path is SQL-side period/bucket expressions per
driver if a host needs it.

## R5. Reuse of spec 012 filter rules (FR-017, FR-019)

**Decision**: the aggregate declaration's `filters` key has exactly the shape of a 012 query declaration's filters,
and validation and SQL building go through the 012 filter unit *(provisional, 012: `src/Query/FilterRules.php` for
declaration and argument validation, `src/Query/FilterSet.php` for the `where` clauses)*. Nothing filter-related is
re-implemented in `src/Aggregate/`.

Consequences carried over unchanged: operators per form (FR-006 of 012), `in` ≤ 20 values, AND only, pseudonym
equality resolved on the server (`pseudonym.unknown`, `scope.excluded` from 014), `filter.denied` for a plain field
above the ceiling, `input.invalid` for malformed filters, canary check on filter values before the query.

**Not carried over**: 012 FR-026 (at least one filter by default). 013 FR-017 cites 012 FR-006 to FR-011 only, and an
unfiltered count is protected by k. An aggregate with no filter is allowed.

## R6. Row scope (FR-018, spec 014 FR-012)

**Decision**: the 014 resolution for the call *(provisional, 014: the resolved scope object returned by the scope
step before the handler)* is applied as `whereIn(column, values)` per restricted scope name, or no clause for mode
`all`. A scope that cannot be resolved refuses the call with `scope.unresolved` before the query, exactly as for
any tool. k is applied after scope and filters because both are in the `WHERE` clause.

## R7. Per-caller daily aggregate budget (FR-014, FR-015, US4)

**Decision**: `AggregateBudget::admit(Caller)` in `src/Aggregate/AggregateBudget.php`, called from `Admission` only
for aggregate tools, after the per-minute `admit()`. Key `aggregate:day:{caller}:{Y-m-d}` in `GateCache::store()`,
TTL `Budget::secondsUntilUtcMidnight(now)`, incremented under the same per-key lock pattern as `Budget::reserve`
(increment first, compare the returned total, roll back on overshoot). Limit:
`min(config aggregates.queries_per_day ?? 100, Caps::AGG_PER_DAY = 500)`. Over the limit: generic refusal, receipt
`aggregate.budget` (new `ReasonCode::AggregateBudget`, decision `refused`).

Every admitted call to a visible aggregate tool counts, including ones later refused for arguments: invalid calls
are the cheap probe, so they spend the budget too (same choice as the per-minute limit, FR-021 of spec 002).

**Store unavailable**: the exception propagates as in `Budget`; the call is not served (failed, `handler.failed`,
reference). Fail closed, never served uncounted. The per-minute, per-session and per-day byte budgets apply as for
any call (FR-015): the response rows go through `Budget::handle`.

**Residual risk (FR-016)**: two overlapping queries whose difference is one small group still reveal it; a group in
which every member shares a value still discloses that value. No rounding and no noise (owner decision). The privacy
doc states this and lists the protections: suppression, the total rule, determinism, 012 filter limits, the daily
query limit, receipts with field names and operators.

## R8. `Field::aggregate()` change (FR-012)

**Decision**: `Transformer` takes `int $k` (from `AggregateSettings::k()`, default floor 5). For an `AGGREGATE`
field, a count in `[1, k-1]` becomes `null` and the payload gets one dropped entry
`['field' => <column>, 'reason' => 'aggregate.suppressed']` (once per column, not per row). 0 and ≥ k are unchanged.
New `ReasonCode::AggregateSuppressed` (`aggregate.suppressed`, decision `dropped`), following `FieldUnlisted`.

Known ceiling, unchanged: the count is the number of rows the payload holds at the transform stage, which is after
the scope drop (014) and after the work bound in `FirewallRunner` (2 × per-call bytes, 10 000 rows). Documented in
`docs/privacy.md`; the aggregate tool is the exact count.

Breaking: tests `tests/Unit/Pipeline/TransformerTest.php` that assert small counts change (pre-010, accepted).

## R9. Receipts (FR-020)

**Decision**: `Receipt::write` gains an optional `?array $aggregate` written as
`"aggregate": {"group_by": [names], "filters": [{"field": name, "op": op}], "k": int, "groups": int, "suppressed": int,
"withheld": bool}`. The `filters` list uses the shape 012 writes for query receipts *(provisional, 012)* so spec 016
documents one shape. No label, count or filter value. Spec 016's structured format lists `aggregate` as an optional
field; adding it is a format-version change there.

## R10. Tool generation, pinning and dispatch (FR-001, FR-002)

**Decision**: a tool entry with an `aggregate` key and no `class` is built by the registry as
`ContextGate\Aggregate\AggregateTool` (name = entry key, declaration and contract from the entry), through the same
factory 012 adds for declared query tools *(provisional, 012: `ToolRegistry::instantiate`)*. Name, description
(includes k and the allowed group-by keys), input schema and annotations come from the declaration and contract only,
so `Fingerprint::of` pins all of them; a change of k, keys or filters hides the tool until re-pinned. The declaration
is hashed into the tool rule hash (`RuleHash`).

`GateCallTool::serve` branches once after `ArgumentsStep`: an `AggregateTool` goes to `AggregateStep` (query, fold,
suppression, normalise, canary, budget, receipt) instead of `FetchStep` + `FirewallStep`, because the output columns
are labels and counts, not contract fields. `AggregateStep` reuses `Normalizer`, `CanaryCheck` (labels are values,
FR-021) and `Budget::handle` in that order.

**No new dependency** (constitution X): grouping uses the query builder; the counter uses the configured cache.
