# Research: Classification Declared on the Model

Code read at HEAD `d744441`. Paths are relative to the repository root.

## State found in the code

| Item | Where | State |
|---|---|---|
| Catalog | `src/Classification/Catalog.php` | `new Catalog(array $map)`; throws `InvalidArgumentException` on an unknown class. `fromFile()` exists and has no caller in `src/`. |
| Catalog built at | `ContractGuard`, `GateServer::contractRefusals`, `FirewallRunner`, `Sources/SchemaDescribeTool`, `CheckCommand`, `AuditCommand`, `ClassifyCommand` (empty) | Each builds `new Catalog(config('context-gate.classification'))` itself: six builds of the same map, per call on some paths. |
| Class order | `src/Classification/Ceiling.php` | `rank()` is private; `allows()` compares ranks. |
| Classification hash | `ContextGateServiceProvider::ruleHashes` | `RuleHash::of(canonical(['classification' => config map, 'firewall' => …]))`; `canonical` sorts keys recursively, so listing order already does not change it. |
| Draft | `ClassifyCommand` writes `app/Gate/classification.php`; `CatalogCheck` fails on its markers | The draft is never loaded as a catalog (no `Catalog::fromFile` caller). `tests/Contract/ClassifyDraftInertTest.php` exists. |
| Allowlisted models | `config('context-gate.sources.eloquent.models')`; `ContextContract::model()` | `ScanCommand` and `ClassifyCommand` filter the first list; contracts name their model. |
| gate:scan | `src/Console/ScanCommand.php` | Report mode only, always exit 0; a missing table yields an empty column list silently. |

Already satisfied, verification only: FR-010 (drafts inert; `gate:classify` writes only `app/Gate/classification.php`),
FR-011 ordering part (canonical JSON), FR-014 (contract rules run against whatever `Catalog` they get), US4 #2.

## R1. Declaration mechanism

**Decision**: one class-level PHP attribute, `ContextGate\Classification\Classify`, holding a column map.

```php
use ContextGate\Classification\Classify;

#[Classify(['status' => 'internal', 'email' => 'personal', 'password' => 'secret'])]
final class Customer extends Model {}
```

- Values are strings (`public`, `internal`, `personal`, `secret`) or `Ceiling` cases. Strings keep FR-002 testable
  (an unknown value is representable and must invalidate the catalog).
- Read with `ReflectionClass::getAttributes(Classify::class)`; the attribute is not instantiated through
  `newInstance()` until its arguments are checked, so a bad value becomes a catalog error, not a `TypeError`.
- No query: the table name comes from `(new $model())->getTable()`, as `ContractValidator::tableOf` does today.
- `@api` (host-facing), `Attribute::TARGET_CLASS`, not repeatable.

**Rationale**: Eloquent models do not declare typed properties for columns, so property attributes would need fake
properties that shadow `__get` and break attribute access. A class-level map sits next to `$fillable` and `$casts`,
which reviewers already read in a migration change.

**Alternatives rejected**: property attributes (above); a static method `classification()` (code that can compute,
query or read env at runtime; an attribute is a constant expression); docblock tags (parsing comments, no IDE
checks); a separate file per model (two places to review).

## R2. Inheritance

**Decision**: walk the class and its parents (`getParentClass()`) up to `Model`. A parent's declaration applies to a
child only when `getTable()` of the child equals the table the parent would use (`(new Parent)->getTable()`, skipped
for abstract parents, which then apply when the child's table is used). Child and parent merge with stricter-wins; a
child less strict than its parent is a conflict reported by `gate:check` (same rule as config widening).

**Rationale**: matches the spec default; stricter-wins keeps one merge rule everywhere.

## R3. Merge and precedence

**Decision**: `EffectiveCatalog` builds, once per process (container singleton), from:

1. the configuration map (today `config('context-gate.classification')`; after spec 009, its effective map — see R7);
2. model declarations from allowlisted models (R4).

For each (table, column): effective = `Ceiling::stricter(a, b)` over every source; absent if none. Conflicts are
collected, never thrown, except an unknown class, which throws `InvalidArgumentException` naming model and column
(the existing path that turns into `config.invalid` and a `gate:check` FAIL).

| Conflict | Runtime | gate:check |
|---|---|---|
| Unknown declared class | catalog invalid → `config.invalid` | FAIL `model <Class> column <col>` |
| Config less strict than model | stricter applies, call served | FAIL `classification.<table>.<column>` |
| Two models differ on one table/column | stricter applies | FAIL naming both models |
| Child less strict than parent | stricter applies | FAIL naming both classes |
| Declared column not in table | no effect | WARN (FR-012) |

`Ceiling` gains `public static function stricter(self $a, self $b): self` (and `rank()` stays private).

**Alternatives rejected**: model wins (lets a model lower a config class: config would no longer be the narrowing
tool); config wins (principle VI: config could widen).

## R4. Allowlisted models (FR-006)

**Decision**: `AllowlistedModels::all(): list<class-string<Model>>` = `sources.eloquent.models` ∪ `model()` of every
contract named by an enabled or disabled tool in `tools` (via `ToolRegistry::contractClassOf`) ∪ models of their
related contracts. De-duplicated, sorted. Non-model strings are ignored (as `ScanCommand` does). No file-system
discovery. `ScanCommand`, `ClassifyCommand` and `EffectiveCatalog` all use this one list.

## R5. gate:scan CI mode (FR-007–FR-009)

**Decision**: `gate:scan --ci [--json]`.

- Reads every allowlisted model's table through its own connection (`getConnection()->getSchemaBuilder()`).
- Exit `0`: every column of every allowlisted table has an effective class.
- Exit `1`: at least one unclassified column, or a model whose table is missing or unreadable (FR-008).
- Exit `2`: the catalog is invalid (unknown class); nothing else is evaluated.
- Text: one line per finding, `unclassified <Model> <table>.<column>` or `unreadable <Model>`. No row value, no
  suggestion (the report mode keeps suggestions).
- JSON: stdout is exactly one object (contracts/gate-scan-ci.md); every other line goes to stderr
  (`FailsToStderr`).
- Routes are not read in CI mode.

**Alternatives rejected**: a separate command (`gate:scan` already reads the models; the spec names it); a `--strict`
flag on `gate:check` (it does not read every table column today and should not gain a schema walk per run).

## R6. Drafts (FR-010)

**Decision**: no code change. Verification tests: a draft with markers next to an unclassified column leaves the CI
mode failing for that column; `gate:classify` writes no file under the model path (file hash before/after).

## R7. Order with spec 009 and hand-off to 016 and 017

- **009 first**: `EffectiveCatalog` takes its config map from 009's effective classification accessor (built-in plus
  host tables). Model declarations merge on top with the same stricter-wins rule. 009's "built-in tables cannot be
  reclassified" is untouched: no model owns a `source_*` table.
- **015 first**: `EffectiveCatalog::configMap()` reads `config('context-gate.classification')`; 009 later replaces
  that single method.
- **016**: receipts already carry `rules.classification`; T026 makes it hash `EffectiveCatalog::map()`, so 016 needs
  no change for it.
- **017**: resources and prompts reuse `app(EffectiveCatalog::class)` for any schema resource; no new catalog build.

## R8. Performance

One reflection read per allowlisted model per process (FR-013). Not cached in a store. `gate:scan --ci` reads table
columns, which `gate:check` does not (stale-declaration WARN reads columns only for declared models).
