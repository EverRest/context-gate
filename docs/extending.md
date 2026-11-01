# Extending

## Supported surface

Classes marked `@api` form the supported extension surface: `ContextContract`, `Field`, `Tool`, the `Source` interface,
`ToolPolicy`, `Redactor`, `Pseudonymizer` and `ReasonCode`. The package is pre-1.0, so a minor version may still break
them, and the changelog says how. Classes marked `@internal` (the pipeline stages, the firewall runner, the call and
list handlers, settings, readiness checks, HTTP middleware and console commands) may change at any time. Do not extend
or call them.

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
for one hour, with a sliding expiry that never reaches past creation plus 7 days. Over stdio they stay in memory (at
most 100 scopes). A scope holds at most 10 000 entries; past that its key rotates.

## Logging

The receipt channel (`channel`) is yours to define: the provider does not overwrite a `logging.channels.<channel>` you
set. Over stdio, stdout belongs to the protocol, so a host-defined channel must not write to stdout. Over HTTP there is
no STDERR, so boot diagnostics go once per process to this channel.

## Publish tags

- `context-gate-config`: `config/context-gate.php`.
- `context-gate-migrations`: the token table migrations.
