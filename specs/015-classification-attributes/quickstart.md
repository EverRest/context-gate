# Quickstart: Classification Declared on the Model

In a host application with the package installed and migrations run.

## 1. Declare on the model

```php
use ContextGate\Classification\Classify;

#[Classify(['id' => 'internal', 'status' => 'internal', 'email' => 'personal'])]
class Customer extends Model {}
```

`config/context-gate.php`:

```php
'sources' => ['eloquent' => ['models' => [App\Models\Customer::class]]],
```

## 2. CI mode

```sh
php artisan migrate --force
php artisan gate:scan --ci          # exit 1, lists every unclassified customers column
php artisan gate:scan --ci --json | jq .
```

Classify the remaining columns and run again: exit 0.

## 3. Stricter wins

```php
'classification' => ['customers' => ['email' => 'internal']],   // less strict than the model: gate:check fails
```

```sh
php artisan gate:check   # FAIL classification.customers.email ... may not widen a model class
```

A call still serves `email` as `personal` (pseudonym, bucket or aggregate per contract). Change it to `secret`:
`gate:check` passes and the column is now refused by any contract that lists it.

## 4. Drafts stay inert

```sh
php artisan gate:classify --force
php artisan gate:scan --ci          # still exit 1 for the same columns
php artisan gate:check              # FAIL app/Gate/classification.php: line N still has a draft marker
git status app/Models               # no change
```

## 5. Hash follows the declaration

```sh
php artisan gate:audit --json | jq '.tools[0].rules.classification'
# edit the #[Classify] map, then
php artisan gate:audit --json | jq '.tools[0].rules.classification'   # differs
```

## Package tests

```sh
vendor/bin/phpunit --filter 'Classify|EffectiveCatalog|ScanCi|ModelClassCheck'
composer style && composer stan && composer length && composer test
```
