# Contract: Host Scope Resolver

Public API added by spec 014 (`@api`, covered by the compatibility policy in the README).

## Interface

```php
namespace ContextGate\Contracts;

use ContextGate\Scope\ScopeGrant;
use ContextGate\Server\Caller;

/**
 * Resolves the row-scope values of one caller for one call.
 *
 * Contract: deny by default. Return a grant only for names the caller really holds. Never read tool arguments,
 * client data or model output. The result is used for this call only and never cached by the gate.
 *
 * @api Supported extension surface: bind your own implementation in a service provider.
 */
interface ResolvesScopes
{
    /**
     * @param list<string> $names scope names in force for this call (the contract and its related contracts)
     * @return array<string, ScopeGrant> name => grant; omit a name the caller does not hold
     */
    public function resolve(Caller $caller, array $names): array;
}
```

## Grants

```php
ScopeGrant::values([12, 15]);   // restricted to federations 12 and 15; 1..100 values, int or string
ScopeGrant::all();              // explicit all-rows grant for this one name
```

`ScopeGrant::values([])` throws. There is no wildcard value.

## Binding

```php
// app/Providers/AppServiceProvider.php
$this->app->bind(\ContextGate\Contracts\ResolvesScopes::class, \App\Gate\FederationScopes::class);
```

Default binding: `ContextGate\Scope\NoScopes` (returns `[]`): every scoped contract is refused until the host binds
its resolver.

## Gate behaviour

| Resolver | Call |
|---|---|
| grant for every name in force, valid, within `scopes.timeout_ms` | served; rows outside the values are removed |
| omits a name, throws, returns anything malformed, over 100 values, too slow | refused `scope.unresolved`, handler not called |
| called for an unscoped contract | never (no names in force) |

Over stdio, `Caller::$id` is the OS user name; over HTTP it is `token:<id>`. After spec 010, `Caller` also carries the
role name.

## Contract declaration

```php
final class EntryContract extends ContextContract
{
    public function model(): string { return Entry::class; }

    public function fields(): array { /* ... */ }

    public function scope(): ?Scope { return Scope::by(['federation' => 'federation_id']); }
}

final class DisciplineContract extends ContextContract
{
    public function scope(): ?Scope { return Scope::none(); }   // reference data
}
```

## Handler access (optimisation only)

```php
$scope = CallScope::current();
$query = Entry::query();
$scope?->apply($query->getQuery(), app(EntryContract::class)->scope());
```

The gate removes out-of-scope rows after the handler anyway.
