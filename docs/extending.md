# Extending

## Container extension points

`Redactor`, `Pseudonymizer` and `ToolPolicy` are not final and are resolved from the container. Rebind one in a
service provider to replace it:

```php
$this->app->bind(\ContextGate\Privacy\Redactor::class, MyRedactor::class);
```

The same applies to the OpenAPI `Transport` and `Resolver`. Tool and contract classes named in `tools` are also built
by the container, so they may take constructor dependencies.

## Cache store

`cache_store` picks the store for budgets, rate limits and day, week and persistent-session pseudonym state
(`null` is the host default). See [configuration.md](configuration.md#cache_store).

HTTP behind php-fpm needs a cache store shared across workers (redis, database). Session pseudonyms live in the cache
for one hour, with a sliding expiry. Over stdio they stay in memory (at most 100 scopes).

## Logging

The receipt channel (`channel`) is yours to define: the provider does not overwrite a `logging.channels.<channel>` you
set. Over stdio, stdout belongs to the protocol, so a host-defined channel must not write to stdout.

## Publish tags

- `context-gate-config`: `config/context-gate.php`.
- `context-gate-migrations`: the token table migrations.
