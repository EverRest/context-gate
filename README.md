# context-gate

Secure MCP gateway for Laravel: the AI receives only what a contract allows.

The package serves an MCP server over stdio (`context-gate`). Every tool is off until you enable it, and
every call writes one metadata-only receipt line to stderr.

Requirements: PHP 8.2 or later, Laravel 12 or 13.

## Install

```bash
composer require everrest/context-gate
php artisan gate:install
```

`gate:install` publishes `config/context-gate.php` with `enabled` set to `false` and `tools` empty. It does
not overwrite an existing file.

## Enable a tool

1. Set `enabled` to `true` in `config/context-gate.php`.
2. Add the tool under `tools`, with its class, its gate ability and the fingerprint of its description:

   ```php
   'tools' => [
       'echo' => [
           'enabled' => true,
           'class' => App\Mcp\EchoTool::class,
           'gate' => 'echo.allow',
           'fingerprint' => '<output of gate:fingerprint echo>',
       ],
   ],
   ```

3. Allow the tool for a caller. A caller sees and calls only the tools listed for it:

   ```php
   'callers' => [
       'alice' => ['tools' => ['echo']],
   ],
   ```

4. Print the fingerprint with `php artisan gate:fingerprint echo` and paste it into the entry.

A tool the caller is not allowed to see answers with the same "not found" error as a missing tool.

## Start the server

```bash
php artisan mcp:start context-gate
```

The server speaks MCP over stdin and stdout. Stdout carries only protocol messages; receipts and
diagnostics go to stderr.

The server refuses to start when `APP_ENV` is `production`. Use authenticated HTTP there instead.

## Test

```bash
composer test
composer stan
composer style
composer length
```
