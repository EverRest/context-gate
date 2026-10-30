# Research: MCP stdio Foundation

## R1. Protocol and transport come from laravel/mcp

- **Decision**: Use `laravel/mcp ^1.1` for JSON-RPC, `initialize`, the stdio transport and the
  `mcp:start` command. Register the server with `Mcp::local()`.
- **Rationale**: Stable 1.x release, native to Laravel 12+ (D36), and it provides the extension
  point we need (`addMethod()`).
- **Alternatives considered**: `mcp/sdk` (0.x, experimental, adds a PSR-7 bridge and several
  dependencies); own JSON-RPC (rejected in D3 revision).

## R2. Overriding the tool methods

- **Decision**: `GateServer extends Laravel\Mcp\Server` and calls
  `addMethod('tools/call', GateCallTool::class)` in its boot path. `tools/list` stays the built-in
  handler, because visibility is decided per primitive (R3).
- **Rationale**: Verified in `laravel_mcp/src/Server.php`: `addMethod()` replaces an entry in the
  `$methods` map, so the override is the only one that runs. `CallTool` is a thin wrapper around
  `ToolInvoker`, so our handler can run the pipeline and then delegate to `ToolInvoker`.
- **Alternatives considered**: overriding `tools/list` too (adds code with no security benefit,
  because `ServerContext::tools()` already applies `eligibleForRegistration()`).

## R3. Per-caller visibility uses shouldRegister()

- **Decision**: Each tool implements `shouldRegister()`, which reads the current caller from the
  container and checks the policy and ceiling. `ServerContext::tools()` filters with
  `eligibleForRegistration()` (verified in `src/Server/ServerContext.php` and `src/Server/Primitive.php`).
- **Rationale**: One place decides visibility for both `tools/list` and `tools/call`. A hidden tool
  is absent from the collection, so the built-in `CallTool` answers with `Tool [name] not found.`
  (code -32602), the same response as for a non-existent tool (D18).
- **Alternatives considered**: a separate list filter (duplicates logic and can drift from the
  call path).
- **Open point (tested in the spike)**: `shouldRegister()` runs through `Container::call`, so the
  caller must be bound in the container for the lifetime of a request. For stdio the caller is the
  OS user of the process, bound once at start.

## R4. Identical response for hidden and non-existent tools

- **Decision**: `GateCallTool` checks visibility first and, when a tool is hidden, throws the same
  `JsonRpcException` text as the built-in lookup: `Tool [{name}] not found.` with code -32602. The
  real reason is written to the receipt only.
- **Rationale**: Byte-identical responses are required by SC-002. The built-in message is already
  generic.
- **Alternatives considered**: a custom error text (creates a difference that can be probed).

## R5. Strict argument validation

- **Decision**: `GateCallTool` validates arguments before the handler runs. Unknown top-level keys
  are rejected with `input.invalid` naming the key. Required keys and types come from the tool's
  `schema()` through `illuminate/json-schema`, validated with the Laravel Validator (D9).
- **Rationale**: The built-in path does not reject unknown keys by default; this must be checked
  in the spike (open point). Rejecting unknown keys before the handler is part of the firewall
  promise.
- **Alternatives considered**: relying on the handler to ignore unknown keys (rejected: a handler
  change could start reading them).

## R6. Refusals are results, not protocol errors

- **Decision**: A per-call policy refusal returns a JSON-RPC result with `isError: true` and text
  `Request refused.`, with reason `policy.denied` only in the receipt. An invalid argument returns
  JSON-RPC error -32602 naming the argument.
- **Rationale**: Matches the built-in `ToolInvoker` convention (errors in `content` with
  `isError`), and keeps the policy reason out of the caller-visible response.

## R7. stdout discipline

- **Decision**: The `context-gate` log channel writes to stderr only. The stdio transport owns
  stdout. A process test starts `mcp:start` and asserts that every stdout line parses as JSON-RPC.
- **Rationale**: `StdioTransport` writes to `STDOUT` with `fwrite`; any stray output breaks the
  session (D11). The Laravel default channel must not be used by the server.
- **Alternatives considered**: suppressing output in the command only (fragile: a provider could
  still print during boot).

## R8. Production refusal

- **Decision**: `GateServer::boot()` checks `app()->environment('production')` and, if true,
  writes the reason to stderr and exits with code 1. The check runs before `start()`.
- **Rationale**: D20. Refusing in code is simpler than a middleware and cannot be bypassed by
  configuration.

## R9. Tool registry and description fingerprints

- **Decision**: Each tool declares `description()`. The approved fingerprint is stored in
  `config/context-gate.php` under `tools.<name>.fingerprint`, computed by `gate:fingerprint`
  (a command added in this feature). At boot, `shouldRegister()` returns false when the
  fingerprint differs and records `tool.pin_mismatch`.
- **Rationale**: Keeps approval reviewable in Git (D32 style). No database is needed in this spec.
- **Alternatives considered**: storing fingerprints in a table (not needed before tokens arrive in 005).

## R10. Install command and configuration

- **Decision**: `gate:install` publishes `config/context-gate.php` with `enabled => false` and an
  empty `tools` map. It refuses to overwrite without `--force`.
- **Rationale**: FR-012, FR-013. Matches the closed-by-default principle.
