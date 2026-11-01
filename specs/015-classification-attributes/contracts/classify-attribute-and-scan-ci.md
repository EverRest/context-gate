# Contract: `#[Classify]` and `gate:scan --ci`

## Attribute (public API, `@api`)

```php
namespace ContextGate\Classification;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Classify
{
    /** @param array<string, string|Ceiling> $columns column => public|internal|personal|secret */
    public function __construct(public array $columns) {}
}
```

Read only from allowlisted models (`sources.eloquent.models` and contract models). Never applied to `source_*`
tables. Effective class = stricter of model, parents (same table) and configuration.

## gate:scan --ci

```text
php artisan gate:scan --ci [--json]
```

| Exit | Meaning |
|---|---|
| 0 | every column of every allowlisted model's table has an effective class |
| 1 | at least one unclassified column, or an allowlisted model whose table cannot be read |
| 2 | the catalog is invalid (unknown class in a declaration or the configuration map) |

Text (stdout):

```text
unclassified App\Models\Customer customers.nickname
unreadable App\Models\Archive
2 problems; classify each column on the model (#[Classify]) or in context-gate.classification
```

JSON (stdout holds only this object; diagnostics on stderr):

```json
{
  "ok": false,
  "unclassified": [{"model": "App\\Models\\Customer", "table": "customers", "column": "nickname"}],
  "unreadable": [{"model": "App\\Models\\Archive"}],
  "invalid": null
}
```

`invalid` is `null` or `{"model": "<class or null>", "table": "<t>", "column": "<c>"}` when exit is 2 (the
configuration map case has `model` null). Lists are sorted by model, table, column. No row value, column type,
default or suggestion appears in either format.

## gate:check lines added

```text
FAIL classification.customers.email: configuration says internal but App\Models\Customer declares personal; configuration may not widen a model class
FAIL classification.customers.email: App\Models\Customer and App\Models\VipCustomer declare different classes (personal, secret)
FAIL classification: App\Models\Customer declares an unknown class for column status
WARN classification.customers.legacy_flag: App\Models\Customer declares a column the table does not have
```
