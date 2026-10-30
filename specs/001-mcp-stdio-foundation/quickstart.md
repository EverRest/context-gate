# Quickstart: MCP stdio Foundation

Validation guide for the feature. Implementation details are in `tasks.md`.

## Prerequisites

- PHP 8.2 or later, Laravel 12 or 13 application, Composer.
- Shell in the application root.

## 1. Install (User Story 6)

```bash
composer require everrest/context-gate
php artisan gate:install
```

Expected: `config/context-gate.php` exists; `tools` is empty; `enabled` is `false`.

## 2. Empty tool list (User Story 1)

```bash
printf '%s\n' \
  '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"qs","version":"1"}}}' \
  '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' \
  | php artisan mcp:start context-gate 2>/dev/null
```

Expected: an `initialize` result and a `tools/list` result with `"tools":[]`. Only JSON lines
appear on stdout.

## 3. Hidden tool looks like a missing one (User Story 2)

Add one test tool to the configuration and allow it for another caller. Call it through the same
command. Expected: `Tool [...] not found.` with code -32602. Check the `context-gate` log: the
receipt has `reason: policy.hidden`.

## 4. Production refusal (User Story 5)

```bash
APP_ENV=production php artisan mcp:start context-gate >/tmp/out.txt 2>/tmp/err.txt; echo "exit=$?"
```

Expected: `exit=1`, `/tmp/out.txt` empty, `/tmp/err.txt` names the reason.

## 5. Gates

```bash
composer test && composer stan && composer style
```

Expected: exit code 0 for each command.
