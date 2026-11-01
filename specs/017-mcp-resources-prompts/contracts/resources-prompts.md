# Contract: Resources and Prompts Methods

JSON-RPC 2.0 over the existing transports. The vendor `Server::send()` adds `resultType` and `_meta.serverInfo` to every
result; they are omitted below. Error texts are fixed (research R6).

## initialize (capabilities per caller)

```json
{"capabilities": {"tools": {"listChanged": false}, "resources": {"listChanged": false}, "prompts": {"listChanged": false}}}
```

`resources` and `prompts` appear only when the caller sees at least one of each. No `subscribe`. `server/discover`
returns the same capabilities.

## resources/list

Request: `{"method": "resources/list", "params": {"cursor": "<optional>"}}`

Result:

```json
{
  "resources": [
    {"uri": "gate://docs/docs/guide.md", "name": "docs/guide.md", "title": "docs/guide.md",
     "description": "Allowlisted documentation file, read through the context gate.", "mimeType": "application/json"},
    {"uri": "gate://schema/App/Models/Entry", "name": "Entry", "title": "App\\Models\\Entry",
     "description": "Schema of an allowlisted model, read through the context gate.", "mimeType": "application/json"}
  ],
  "nextCursor": "eyJvZmZzZXQiOjEwMDB9"
}
```

- Order: docs (sorted by path), then schemas (sorted by class). At most `resources_page_size` per page, at most
  `RESOURCE_LIST` in total. `nextCursor` absent on the last page.
- No resource visible: error `{"code": -32601, "message": "The method [resources/list] was not found."}`.

## resources/read

Request: `{"method": "resources/read", "params": {"uri": "gate://docs/docs/guide.md"}}`

Result:

```json
{"contents": [{"uri": "gate://docs/docs/guide.md", "mimeType": "application/json",
  "text": "{\"source\":\"docs/guide.md\",\"text\":\"<untrusted …>…</untrusted>\",\"truncated\":false}"}]}
```

`text` is byte-equal to `content[0].text` of `tools/call` `docs.read` with `{"path": "docs/guide.md"}` (schema: of
`schema.describe` with `{"model": "App\\Models\\Entry"}`), including the untrusted marking.

Errors:

| Case | code | message |
|---|---|---|
| caller sees no resource | -32601 | `The method [resources/read] was not found.` |
| unknown, hidden, pin mismatch, denylisted, outside allowlist, link escape, missing file, unlisted model, malformed URI | -32602 | `Resource not found.` |
| refused (policy, rate, budget, config, canary in arguments) | -32603 | `Request refused.` (rate: `Request refused. Retry after N seconds.`) |
| failed (canary in content, handler, schema.missing, transform) | -32603 | `Request failed. Reference: r_…` |

## prompts/list

```json
{"prompts": [{"name": "qualification-status", "title": "qualification-status",
  "description": "Guide the assistant to report qualification status for one discipline.",
  "arguments": [{"name": "discipline", "description": "Discipline code, e.g. ATH.", "required": true}]}]}
```

No prompt visible: `-32601 The method [prompts/list] was not found.` Not paged (prompts are a short host list).

## prompts/get

Request: `{"method": "prompts/get", "params": {"name": "qualification-status", "arguments": {"discipline": "ATH"}}}`

Result:

```json
{"description": "Guide the assistant to report qualification status for one discipline.",
 "messages": [{"role": "user", "content": {"type": "text",
   "text": "Use entries.count and entries.list to report qualification status for discipline ATH."}}]}
```

Errors:

| Case | code | message |
|---|---|---|
| caller sees no prompt | -32601 | `The method [prompts/get] was not found.` |
| unknown, hidden, pin mismatch, invalid at load, refers to a tool the caller cannot see | -32602 | `Prompt not found.` |
| arguments fail the schema | -32602 | `Invalid arguments: <rule>` |
| canary in arguments, policy denied, rate limit, config invalid | -32603 | `Request refused.` |

## Removed methods (unknown method, `-32601 The method [x] was not found.`)

`resources/templates/list`, `completion/complete`, `subscriptions/listen`, and any other method not listed in
research R2.

## Receipts

```json
{"id":"r_…","at":"…","caller":"token:12","kind":"resource","tool":"docs","uri_hash":"3f1c…","decision":"allow","reason":null,"rules":{…}}
{"id":"r_…","at":"…","caller":"token:12","kind":"prompt","tool":"qualification-status","decision":"invalid","reason":"input.invalid","rules":{}}
```
