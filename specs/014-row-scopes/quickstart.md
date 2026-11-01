# Quickstart: Row Scopes per Caller

Validates US1, US2, US3 and US5 by hand in a host application. Automated equivalents are in tasks.md.

## 1. Declare every contract

Each contract returns `Scope::by([...])` or `Scope::none()` from `scope()`. Then:

```bash
php artisan gate:check
```

Expected: no `contract.scope_undeclared` line. A contract without `scope()` makes `gate:check` exit 1 and names it.

## 2. Bind a resolver

```php
final class FederationScopes implements ResolvesScopes
{
    public function resolve(Caller $caller, array $names): array
    {
        $holder = Holder::forCaller($caller->id);          // host lookup, never from arguments
        if ($holder === null) {
            return [];
        }

        return $holder->isSupport()
            ? ['federation' => ScopeGrant::all()]
            : ['federation' => ScopeGrant::values($holder->federationIds())];
    }
}
```

## 3. Check the rules

With entries of federations F and G seeded and the `entries.list` tool enabled:

| Caller | Expected response | Expected receipt |
|---|---|---|
| holds F | only F rows | `scope: [{name: federation, mode: restricted, removed: <G rows>}]` |
| holds nothing | `Request refused.` | `reason: scope.unresolved` |
| support (all) | F and G rows | `mode: all` |
| holds F, sends a G athlete pseudonym | `Request refused.` (same as an unknown pseudonym) | `reason: scope.excluded` |

Remove F from the caller in the host: the next call returns no F rows (no cache).

## 4. Confirm no value leaks

```bash
grep -c '<a federation id>' storage/logs/*.log   # expected 0 for the receipt channel
```
