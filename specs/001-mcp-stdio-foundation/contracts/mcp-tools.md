# Contract: MCP tools over stdio

Protocol: JSON-RPC 2.0 over stdio, as provided by `laravel/mcp`. Only the two methods overridden by
this feature are described here. `initialize`, `ping` and other methods are unchanged.

## tools/list

Request: standard `tools/list`.

Response: `result.tools` contains only tools visible to the caller (FR-004). Each entry has `name`,
`description` and `inputSchema`. With no enabled tools, `tools` is an empty array (FR-003).

## tools/call

### Success

Request: `{"name": "<tool>", "arguments": {...}}`.

Response: `result` as produced by the tool handler through `ToolInvoker`, with `isError: false`.

### Hidden or unknown tool (FR-005)

Response, identical for both cases:

```json
{"jsonrpc":"2.0","id":7,"error":{"code":-32602,"message":"Tool [orders.status] not found."}}
```

The receipt records `decision: hidden` and `reason: policy.hidden`, or `tool.pin_mismatch`.

### Missing name

```json
{"jsonrpc":"2.0","id":7,"error":{"code":-32602,"message":"Missing [name] parameter."}}
```

### Unknown argument (FR-007)

```json
{"jsonrpc":"2.0","id":7,"error":{"code":-32602,"message":"Argument [foo] is not allowed for tool [orders.status]."}}
```

The handler is not called. Receipt: `decision: invalid`, `reason: input.invalid`.

### Policy refusal (FR-008)

```json
{"jsonrpc":"2.0","id":7,"result":{"content":[{"type":"text","text":"Request refused."}],"isError":true}}
```

The rule details are not included. Receipt: `decision: denied`, `reason: policy.denied`.

### Handler failure (FR-015)

```json
{"jsonrpc":"2.0","id":7,"result":{"content":[{"type":"text","text":"Request failed. Reference: r_3fa19c2e"}],"isError":true}}
```

The exception message goes to the `context-gate` log channel only. Receipt: `decision: failed`,
`reason: handler.failed`.

## Process contract (stdio)

- Started with `php artisan mcp:start context-gate`.
- Refuses to start when `APP_ENV=production`: exit code 1, reason on stderr, nothing on stdout.
- stdout contains only JSON-RPC messages, one per line (FR-011).
