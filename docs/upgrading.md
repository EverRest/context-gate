# Upgrading

The package is pre-1.0: a minor version may break. The full list is in the [changelog](../CHANGELOG.md); the steps
below cover what a 0.x user must do. Work through them in order, then run `php artisan gate:check`.

## From a 0.x release before the review fixes

1. **Re-run the fingerprints, twice if you already did once.** The fingerprint is now the sha256 of the canonical JSON
   of the tool's name, description, input schema and annotations. An earlier fix changed the recipe from description
   only to name, description and schema; this one changes it again. For every enabled tool:

   ```bash
   php artisan gate:fingerprint <tool>
   ```

   Paste the output into `tools.<tool>.fingerprint`. A tool with a stale fingerprint stays hidden (`tool.pin_mismatch`).
2. **Key HTTP callers as `token:<id>`.** `callers` is looked up by caller id, and over HTTP the id is `token:<id>`.
   `gate:token` prints it and `gate:token --list` has a `caller` column. A token whose key is missing sees no tools.
3. **Check tool entries.** An entry accepts only `enabled`, `class`, `gate`, `fingerprint` and `contract`. Remove any
   other key (for example `risk`, `max_bytes` or `calls_per_minute`); it is `config.invalid`. There is no per-tool
   limit. Every tool needs a `gate` ability that is defined with `Gate::define` (the Gate user is a
   `ContextGate\Server\Caller`).
4. **Check tool names.** They must match `^[A-Za-z0-9_.-]{1,128}$`; the server fails to boot otherwise.
5. **Fix contracts.**
   - `Field::date()` accepts only `year` and `month`; the `day` precision is gone.
   - `Field::bucket()` takes 1 to 20 strictly ascending breaks. With two or more, every adjacent gap must be at least
     `max(2, 5%)` of the span from the first to the last break, so unit-width buckets are rejected.
   - A contract that breaks a load rule is refused at call time with `config.invalid`. A contract column with no class
     in the catalog now refuses the call instead of being dropped. Run `gate:check` to find them.
6. **Run the migrations.** `php artisan migrate` creates the token tables that `gate:check` requires.
7. **Enable the PHP extensions curl, mbstring and intl.** `gate:check` fails without them; `ext-curl` is now in
   `composer.json`.
8. **Update HTTP clients.** A valid token that lacks `gate:read`, has an unknown ability or no (or more than one)
   ceiling now gets `403` with `WWW-Authenticate: Bearer error="insufficient_scope"`. `401` is only for a missing,
   invalid, expired or revoked token. A bad argument is an `isError` tool result (`Invalid arguments: ...`), not
   JSON-RPC `-32602` or HTTP 400; `-32602` remains for a missing tool name or an unknown tool.
9. **Expect `enabled = false` to disable everything.** Every tool is hidden: `tools/list` is empty and `tools/call`
   answers `Tool not found.`.
10. **Rebinds.** `Pseudonymizer`, `Redactor` and `ToolPolicy` are no longer `final` and resolve from the container.
    Rebind them in a service provider instead of patching the package. Only classes marked `@api` are supported;
    `@internal` classes may change at any time.
11. **Rotating `APP_KEY`** no longer breaks cached pseudonym state for days: an unreadable state is replaced.
12. **`route:cache` and `optimize`** now work: the guard and refusal handlers are registered on every boot.
