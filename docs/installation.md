# Installation

context-gate is a Laravel package. It needs PHP 8.2 or later and Laravel 12 or 13.

## 1. Require the package

```bash
composer require everrest/context-gate
```

Until a stable version is tagged, Composer refuses the unconstrained command with a minimum-stability error. Use
`composer require everrest/context-gate:@dev` (or a version constraint) instead.

## 2. Publish the configuration

```bash
php artisan gate:install
```

This writes `config/context-gate.php`. Every tool is disabled: `enabled` is `false` and `tools` is empty, so nothing
is visible to an assistant until you add tools. Run `php artisan gate:install --force` only to overwrite a file you
have not edited. See [configuration.md](configuration.md) for every key.

Publish tags: `context-gate-config` (the config file) and `context-gate-migrations` (the token tables), for example
`php artisan vendor:publish --tag=context-gate-migrations`.

**Upgrading:** the tool fingerprint now covers the tool's name, description and input schema. Re-run
`php artisan gate:fingerprint <tool>` for every enabled tool and update `fingerprint` in the config; until then the
tool stays hidden.

## 3. Check the installation

```bash
php artisan gate:check
php artisan gate:check --json
```

`gate:check` runs every installation check (configuration, catalog, contracts, canaries, extensions, origins, tokens
and transport) and prints one line per result. It exits `0` only when no line is `FAIL`. Fix each `FAIL` and run it
again before the gate serves traffic.

## 4. Quickstart: first MCP session over stdio

With no tools configured, the gate answers the handshake and lists nothing:

```bash
printf '%s\n' \
  '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"qs","version":"1"}}}' \
  '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' \
  | php artisan mcp:start context-gate 2>/dev/null
```

Expected: an `initialize` result, and a `tools/list` result with `"tools":[]`. Only JSON lines appear on stdout;
diagnostics go to stderr.

## Not supported yet

- No OAuth 2.1 or Protected Resource Metadata: HTTP access uses static Sanctum bearer tokens only. OAuth is planned
  after 1.0.
- Laravel 12 and 13 only; Lumen is not supported.

## 5. Next steps

- Enable and classify tools: [configuration.md](configuration.md).
- Issue HTTP access tokens for remote assistants: [tokens.md](tokens.md).
- Review what leaves the application: [privacy.md](privacy.md).
- Audit the exposed tools at any time with `php artisan gate:audit` (add `--json` for a machine-readable report).
