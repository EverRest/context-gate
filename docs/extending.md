# Extending

## Supported surface

Classes marked `@api` form the supported extension surface: `ContextContract`, `Field`, `Tool`, the `Source`
interface, `ReasonCode` and the three interfaces in `ContextGate\Contracts` (`RedactsText`, `IssuesPseudonyms`,
`AuthorizesTools`). The package is pre-1.0, so a minor version may still break them, and the changelog says how.
Classes marked `@internal` (the pipeline stages, the firewall runner, the call and list handlers, settings, readiness
checks, HTTP middleware and console commands) may change at any time. Do not extend or call them.

`Redactor`, `Pseudonymizer` and `ToolPolicy` stay non-final, but subclassing them is `@internal`: bind your own
implementation of the interface instead.

## Container extension points

Bind an implementation of an interface in a service provider to replace the default:

| Interface | Default | Method |
|---|---|---|
| `ContextGate\Contracts\RedactsText` | `Redactor` | `redact(string $text): string` |
| `ContextGate\Contracts\IssuesPseudonyms` | `Pseudonymizer` | `pseudonym()`, `pseudonyms()`, `resolve()` |
| `ContextGate\Contracts\AuthorizesTools` | `ToolPolicy` | `allows(string $tool, Caller $caller, array $arguments): bool` |

Bind the interface itself. The interface is the only extension point: binding the concrete class (`Redactor`,
`Pseudonymizer`, `ToolPolicy`) does not replace the default, because the interface always resolves to the package class.

```php
use ContextGate\Contracts\RedactsText;

final class MyRedactor implements RedactsText
{
    public function redact(string $text): string
    {
        return preg_replace('/\bORD-\d{6}\b/', '[order]', $text) ?? '';
    }
}

$this->app->bind(RedactsText::class, MyRedactor::class);
```

`RedactsText` must not throw on valid UTF-8 and must not keep the input. `DocsSource`, when you build it outside the
service provider, needs `RedactsText` bound in the container.

```php
use ContextGate\Contracts\AuthorizesTools;
use ContextGate\Server\Caller;

final class MyPolicy implements AuthorizesTools
{
    public function allows(string $tool, Caller $caller, array $arguments): bool
    {
        return $tool === 'orders.status' && $caller->id === 'token:12'; // deny by default
    }
}

$this->app->bind(AuthorizesTools::class, MyPolicy::class);
```

`allows()` receives the arguments after schema validation and pseudonym resolution. It must deny by default: any tool,
caller or error you do not explicitly allow returns `false`.

```php
use ContextGate\Contracts\IssuesPseudonyms;

final class MyPseudonyms implements IssuesPseudonyms
{
    public function pseudonym(string $prefix, string $table, string $id, string $scope, ?string $caller = null): string { /* ... */ }

    public function pseudonyms(string $prefix, string $table, array $ids, string $scope, ?string $caller = null): array { /* id => pseudonym */ }

    public function resolve(string $pseudonym, string $scope, ?string $caller = null): ?array { /* ['table' => ..., 'id' => ...] or null */ }
}

$this->app->bind(IssuesPseudonyms::class, MyPseudonyms::class);
```

An `IssuesPseudonyms` implementation is trusted: it is part of the security path, and the package cannot detect one
that returns the raw id. It must be stable per (scope, id), never return or embed the raw id, and `resolve()` must
return `null` when the pseudonym is unknown or expired (the call then fails with `pseudonym.unknown`). Session state
control stays on the concrete class: `Pseudonymizer::usePersistentSessions()` and `Pseudonymizer::flushSessions()` are
static and are not part of the interface.

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
