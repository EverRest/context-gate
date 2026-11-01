# Quickstart: MCP Resources and Prompts

Prerequisites: spec 015 (effective catalog) for schema resources; spec 016 receipt format includes `kind`.

## Documents and schemas as resources

1. Configure the sources (unchanged keys): `sources.docs.paths => ['docs']`,
   `sources.eloquent.models => [App\Models\Entry::class]`.
2. Enable the entries and give each an ability:

   ```php
   'resources' => [
       'docs'   => ['enabled' => true, 'gate' => 'read-docs',   'fingerprint' => '…'],
       'schema' => ['enabled' => true, 'gate' => 'read-schema', 'fingerprint' => '…'],
   ],
   'callers' => ['token:12' => ['tools' => [], 'resources' => ['docs', 'schema']]],
   ```

   Define the abilities with `Gate::define('read-docs', …)` as for tools.
3. `php artisan gate:fingerprint docs --resource` and `… schema --resource`; paste each value.
4. `php artisan gate:check` must pass.
5. A client now sees the `resources` capability, lists `gate://docs/…` and `gate://schema/…` URIs and reads them.
   Each read is receipted with `kind: resource` and counts against the same rate and byte budgets as tool calls.

## A host prompt

```php
final class QualificationPrompt extends \ContextGate\Server\Prompt
{
    public function name(): string { return 'qualification-status'; }
    public function description(): string { return 'Guide the assistant to report qualification status for one discipline.'; }
    public function schema(): array
    {
        return ['type' => 'object', 'required' => ['discipline'], 'properties' => [
            'discipline' => ['type' => 'string', 'maxLength' => 16, 'description' => 'Discipline code, e.g. ATH.'],
        ]];
    }
    public function template(): string { return 'Use entries.count and entries.list to report qualification status for discipline {{discipline}}.'; }
    public function tools(): array { return ['entries.count', 'entries.list']; }
}
```

```php
'prompts' => ['qualification-status' => ['enabled' => true, 'class' => QualificationPrompt::class, 'gate' => 'use-prompts', 'fingerprint' => '…']],
'callers' => ['token:12' => ['tools' => ['entries.count', 'entries.list'], 'prompts' => ['qualification-status']]],
```

`php artisan gate:fingerprint qualification-status --prompt`, then `gate:check`. The prompt is listed only for callers
that can also see both tools.

## Verify

- With nothing configured, `initialize` advertises only `tools` and `resources/list` answers
  `The method [resources/list] was not found.`
- Change the template: the prompt disappears until re-pinned.
- `php artisan gate:audit` lists the resource entries and the prompt with fingerprint state and rule hashes.
