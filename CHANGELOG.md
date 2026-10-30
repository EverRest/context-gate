# Changelog

All notable changes to this project are documented in this file.

## Unreleased

### 0.1.0-dev: MCP stdio foundation

- `context-gate` MCP server over stdio (`php artisan mcp:start context-gate`), built on `laravel/mcp`.
- Closed-by-default configuration: no tool is visible until enabled, and the tool list is empty by default.
- Hidden and unknown tools share one "not found" response; the reason goes to the receipt only.
- Arguments are validated against the tool schema before the policy and the handler run.
- Tool descriptions are fingerprinted; a changed description hides the tool until it is re-approved.
- Handler failures return `Request failed. Reference: r_...`; the exception text never reaches the caller.
- One metadata-only receipt line per call on the `context-gate` log channel, written to stderr.
- Production refusal: the stdio server does not start when `APP_ENV` is `production`.
- `gate:fingerprint` command prints a tool's description fingerprint.
