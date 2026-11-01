# Research: MCP Resources and Prompts

Code read at HEAD `d744441` (after `b59bd05`): `src/Server/{GateServer,GateListTools,GateCallTool,PolicyStep,Admission,
ArgumentsStep,FetchStep,FirewallStep,FirewallRunner,ResponseBuilder,ReasonCode,Receipt,Fingerprint,ToolRegistry,
Caller,Tool,SourceTools}.php`, `src/Server/Sources/{DocsReadTool,SchemaDescribeTool}.php`,
`src/Source/{DocsSource,DocsPathPolicy,SchemaSource}.php`, `src/Policy/ToolPolicy.php`, and `vendor/laravel/mcp`
v1.1.2. Specs 015 and 016 have no plan yet; names taken from them are marked *(provisional, 015/016)*.

## R1. Does laravel/mcp support resources and prompts? (spec assumption, principle X)

**Answer: yes. No new dependency is needed.**

Evidence in `vendor/laravel/mcp` (v1.1.2, per `vendor/composer/installed.json`):

- `src/Server.php:59-65` defines `CAPABILITY_RESOURCES = 'resources'` and `CAPABILITY_PROMPTS = 'prompts'`.
- `src/Server.php:131-144`: the base `$methods` map routes `resources/list` (`Methods/ListResources`),
  `resources/read` (`Methods/ReadResource`), `resources/templates/list`, `prompts/list` (`Methods/ListPrompts`),
  `prompts/get` (`Methods/GetPrompt`), plus `completion/complete`, `server/discover`, `subscriptions/listen`.
- `src/Server/Resource.php` (uri, mimeType, `toArray()` with `name`, `title`, `description`, `uri`, `mimeType`),
  `src/Server/Prompt.php` and `src/Server/Prompts/Argument.php` (`name`, `description`, `required`): the payload shapes.
- `src/Server.php:183` `addMethod()` replaces any handler, as `GateServer` already does for `tools/list` and
  `tools/call`.
- `src/Server.php:255-276` `createContext()` runs for every message and reads capabilities from the protected
  `resolvedCapabilities()` (`src/Server.php:429`); `Methods/Initialize.php` and `Methods/Discover.php` return
  `$context->serverCapabilities`. Overriding `resolvedCapabilities()` in `GateServer` gives per-caller advertisement.
- `src/Server/Pagination/CursorPaginator.php`: offset cursor (base64 JSON `{"offset": n}`), usable for a URI list.

**What we do not reuse**: the vendor `ListResources`/`ReadResource`/`ListPrompts`/`GetPrompt` handlers resolve
primitives from `$this->resources` / `$this->prompts` with no caller, no firewall and no receipt, and
`ReadResource` echoes the URI in its error (`Resource [<uri>] not found.`, `Methods/Concerns/ResolvesResources.php`).
`GateServer` registers its own handlers for the four methods (same pattern as `GateListTools`/`GateCallTool`) and
uses only the vendor constants, the capability plumbing and the paginator.

## R2. Pre-existing gap: vendor methods reachable today

`GateServer` sets `$capabilities` to tools only and replaces `tools/list` and `tools/call`, but the base `$methods`
map still routes every other method to the vendor handlers. Today, on a stock install:

| Method | Today | After this spec |
|---|---|---|
| `resources/list`, `prompts/list` | vendor result `{"resources": []}` / `{"prompts": []}` | caller sees ≥ 1: gate list; else `-32601 The method [x] was not found.` |
| `resources/read`, `prompts/get` | vendor error naming the URI / name | gate handlers (R5, R7) |
| `resources/templates/list` | vendor empty list | removed: unknown method (templates are out of scope) |
| `completion/complete` | `-32601 Server does not support completions capability.` | removed: unknown method (edge case, out of scope) |
| `subscriptions/listen` | acknowledgement + result (a subscription handshake) | removed: unknown method (principles II/IV, out of scope) |
| `server/discover` | vendor result with capabilities | kept; capabilities are per caller (R9) |

**Decision**: `GateServer` declares its own `protected array $methods` with exactly `initialize`, `ping`,
`server/discover`, `tools/list`, `tools/call`, `resources/list`, `resources/read`, `prompts/list`, `prompts/get`.
Anything else hits the vendor's own `METHOD_NOT_FOUND` branch (`src/Server.php:227-233`).

**Contradiction flagged**: SC-001 says "with nothing configured, advertised capabilities and answers are unchanged from
today", while US4's Independent Test and the completion edge case require `resources/list` / `prompts/list` /
`completion/complete` to answer like an unknown method. Today they do not (table above). This plan follows US4,
FR-009 and the edge case (deny by default) and reads SC-001 as "capabilities and tool answers unchanged". The spec
should reword SC-001; maintainer to confirm.

## R3. Which sources become resources; URIs (FR-001, FR-002)

**Decision**: two resource entries, fixed in code, configured under `resources`:

| Entry | Kind | URI | Content = tool |
|---|---|---|---|
| `docs` | document | `gate://docs/<path relative to the application root>` (each segment rawurlencoded) | `docs.read` with `{"path": <path>}` |
| `schema` | schema | `gate://schema/<model class with \ replaced by />` | `schema.describe` with `{"model": <class>}` |

- Derived from configuration only (`sources.docs.paths`, `sources.eloquent.models`), never from an absolute path,
  never from a data value.
- Parsing is strict: scheme `gate`, host `docs` or `schema`, rest decoded once; a decoded path that differs from
  re-encoding it, contains `%2F` or `%5C` after decode, or is not exactly a listed file / allowlisted class is unknown.
  The docs path then goes through `DocsPathPolicy::refusalStep` (folding, `..`, look-alikes, denylist, links) and
  `DocsSource` itself, exactly as `docs.read` (edge case "URI with `..`, encoded separator, …").
- Routes, config keys and OpenAPI stay tools only (out of scope).

**Why one entry per kind, not per file**: the fingerprint (R8) pins what the client sees about the entry, and a
per-file pin would hide every document when one file is added, while the edge case requires new files to appear on
the next list.

## R4. Listing (FR-015, edge cases)

**Decision**: `GateListResources` enumerates allowlisted doc files (recursive, regular files only, sorted by relative
path, each filtered by `DocsPathPolicy::refusalStep(path) === null`) and allowlisted models, for the entries visible
to the caller. Enumeration stops at `Caps::RESOURCE_LIST = 10000` entries; files past it are not listed and remain
readable by URI if allowed. Pages use the vendor `CursorPaginator` with page size
`min(resources.page_size ?? 1000, Caps::RESOURCE_PAGE = 1000)`. The cursor holds an offset into a list of
configuration-derived URIs, not a data value. Listing writes no receipt and uses no budget, like `tools/list`.

Each listed item: `{"uri", "name" (path or short model name), "title", "description", "mimeType": "application/json"}`.
No file size, no modification time (they change and are host metadata).

## R5. Resource reads go through the same path and share budgets (FR-003, FR-011, clarification)

**Decision**: `GateReadResource` runs, in order, the same steps as `tools/call` for the matching tool:

1. visibility: gate enabled, entry enabled, pinned, listed for the caller (`callers.<id>.resources`); URI parses to
   a listed path / allowlisted model; else `Resource not found.` (R6), receipt `policy.hidden` / `resource.pin_mismatch`.
2. `Admission::check` — `ActiveSettings` valid, then the per-minute `Budget::admit()` on the **same** key
   `gate:{caller}` as tools.
3. `ArgumentsStep` with the matching tool (`DocsReadTool` / `SchemaDescribeTool`), its pseudo contract and the
   arguments `{"path": …}` / `{"model": …}`: canary on arguments, schema, policy (`AuthorizesTools` with the name
   `resource:<entry>`, R10), ceiling.
4. `FetchStep` — the tool's own `handle()`, so `DocsSource`/`SchemaSource` run unchanged (path policy, read cap,
   normaliser, redaction, canary in the source).
5. `FirewallStep` with the same `Budget` instance built by `GateCallTool::budget()` (extracted to a shared static),
   so bytes count against the **same** session and day keys (`budget:session:{caller}`, `budget:day:{caller}:{date}`).
   Same stages, same order, same quarantine of the text field as untrusted.
6. Receipt (kind `resource`), then the response `{"contents": [{"uri", "mimeType": "application/json", "text"}]}`
   whose `text` is byte-equal to the tool result's `content[0].text` (SC-006).

No separate or additional allowance exists anywhere; a refusal for a budget writes the same reason code as a tool
call (`budget.session`, `budget.daily`, `rate.limited`). SC-007 is tested by mixing reads and calls.

**Reuse**: `FirewallStep::run` gets an optional renderer (default `ResponseBuilder::result`) so the resource handler
renders `contents` from the same rows. No stage is copied.

## R6. Refusals for resources and prompts (FR-009, constitution VII)

`resources/read` and `prompts/get` results have no `isError`; a refusal must be a JSON-RPC error. Fixed texts:

| Case | Error |
|---|---|
| no resource visible to caller (capability absent) | `-32601 The method [resources/read] was not found.` (vendor text, identical to an unknown method) |
| unknown, hidden, pin-mismatched, denylisted, outside allowlist, missing file, unlisted model | `-32602 Resource not found.` |
| prompt unknown, hidden, pin-mismatched, invalid at load, refers to an invisible tool | `-32602 Prompt not found.` |
| prompt argument schema error | `-32602 Invalid arguments: <validator message>` |
| refused (policy, canary on arguments, rate, budget, config) | `-32603 Request refused.` (rate limit adds `Retry after N seconds.`) |
| failed (handler, canary in content, transform, schema.missing) | `-32603 Request failed. Reference: r_…` |

The validator message names the schema rule, never the value (existing `ArgumentValidator`). The URI and the name are
never echoed.

## R7. Prompts (FR-004 to FR-007)

**Decision**: a host prompt is a class extending the new `@api` `ContextGate\Server\Prompt`:

```php
abstract class Prompt {
    abstract public function name(): string;          // same charset rule as tool names
    abstract public function description(): string;
    /** JSON schema: type object, properties of type string only, each with maxLength ≤ Caps::PROMPT_ARGUMENT */
    abstract public function schema(): array;
    /** Fixed text with {{argument}} placeholders. */
    abstract public function template(): string;
    /** @return list<string> tool names the prompt refers to */
    abstract public function tools(): array;
}
```

- **Arguments are strings**: MCP prompt arguments are string-valued (`Prompts/Argument.php`: name, description,
  required). `prompts/list` renders the schema's properties as that list. A schema with a non-string property, no
  `maxLength`, or `maxLength` above `Caps::PROMPT_ARGUMENT = 1000` is invalid at load.
- **Validation like tools**: `ArgumentValidator` (same class) on the schema; canary check on arguments
  (`FirewallRunner::argumentsRefusal`, no contract) before the template is filled.
- **Fill**: `strtr($template, ['{{name}}' => $value, …])` with the values as sent; a pseudonym string is inserted as
  sent, never resolved (FR-006). No source, tool, query or pseudonym store is touched.
- **Load check (FR-007)**: at registry build, the template is run through `CanaryCheck` and the configured
  `RedactsText`; a canary hit or any redaction change makes the prompt invalid. An invalid prompt is hidden, the get
  answers `Prompt not found.` with receipt `config.invalid`, and `PromptCheck` fails `gate:check` naming it.
- **Result**: `{"description": …, "messages": [{"role": "user", "content": {"type": "text", "text": <filled>}}]}`.
- **Budgets**: a get counts toward the per-minute limit (`Budget::admit()`, same key). It serves no data bytes, so no
  byte budget (FR-011).
- **Tool references**: a prompt is visible only when every name in `tools()` is visible to the caller
  (`ToolRegistry::isVisible`). An unknown tool name in `tools()` is a load error.

## R8. Fingerprint pinning (FR-012, FR-013)

**Decision**: `Fingerprint` gains `ofResource(string $entry): string` and `ofPrompt(Prompt $p): string`, both sha256
of the sorted-key JSON (the existing `sorted()` helper) of:

- resource: `{"kind": "resource", "name": <entry>, "description", "mimeType", "uriTemplate": "gate://docs/{path}"|"gate://schema/{model}"}`
- prompt: `{"kind": "prompt", "name", "description", "schema", "template", "tools"}`

The `kind` member keeps a tool, resource and prompt with the same name from sharing a pin. `gate:fingerprint` gains
`--resource` and `--prompt` options and prints only the fingerprint. A mismatch hides the entry (absent from lists),
and a read/get records `resource.pin_mismatch` / `prompt.pin_mismatch` (new reason codes, decision `hidden`,
following `tool.pin_mismatch`).

## R9. Capability advertisement per caller (FR-010)

**Decision**: `GateServer::resolvedCapabilities()` returns `tools` always (as today), adds
`resources => ['listChanged' => false]` only when the caller sees ≥ 1 resource entry, and
`prompts => ['listChanged' => false]` only when it sees ≥ 1 prompt. No `subscribe` key and no `listChanged: true`:
no notifications. Any exception while computing visibility returns tools only (fail closed) and goes to the
last-resort note. The caller is known here: HTTP binds it in `boot()` per request (`bindTokenContext`), stdio binds the
OS user at provider registration.

The same visibility function backs the capability, the list handlers and the read/get handlers, so a method answers
`METHOD_NOT_FOUND` exactly when its capability is absent.

## R10. Configuration, callers, ability (FR-008)

```php
'resources' => [
    'docs'   => ['enabled' => false, 'gate' => null, 'fingerprint' => null],
    'schema' => ['enabled' => false, 'gate' => null, 'fingerprint' => null],
],
'prompts' => [
    // 'qualification-status' => ['enabled' => false, 'class' => App\Gate\QualificationPrompt::class, 'gate' => null, 'fingerprint' => null],
],
'callers' => ['token:12' => ['tools' => [...], 'resources' => ['docs'], 'prompts' => ['qualification-status']]],
'resources_page_size' => null,   // ≤ Caps::RESOURCE_PAGE
```

- Resource entry keys are fixed (`docs`, `schema`); any other key is a settings violation.
- `Caller` gains `resources` and `prompts` lists, read like `tools`. `SettingsValidator` checks each listed name
  exists (as for tools, spec 007 R9).
- **Ability**: the `AuthorizesTools` interface is reused unchanged; the gate passes the name `resource:<entry>` or
  `prompt:<name>`, and `ToolPolicy` reads `gate` from `context-gate.resources.<entry>` / `context-gate.prompts.<name>`
  for those prefixes. Tool names cannot contain `:` (`ToolRegistry` name rule), so no collision; a host's own
  `AuthorizesTools` that does not know the prefix denies by its contract (deny by default).
- The resource entries need the matching source configured (`sources.docs.paths` / `sources.eloquent.models`) but
  **not** the `docs.read` / `schema.describe` tool to be enabled: resources are their own entries with their own
  ability, and only the tool classes are reused.

## R11. Receipts and the kind field (FR-014, spec 016)

**Decision**: `Receipt::write` gains `string $kind = 'tool'` written on every receipt as `"kind"`, and an optional
`?string $uriHash` written as `"uri_hash"`. For resources: `tool` = the entry name (`docs` / `schema`), `kind` =
`resource`, `uri_hash` = first 16 hex of sha256 of the URI. For prompts: `tool` = the prompt name, `kind` = `prompt`.
Never the path, the model class, the arguments or the content.

Always writing `kind` changes the line format: spec 016 lists `kind` as a documented field and a label field
(bounded: three values) in the structured format version 1 *(provisional, 016)*. If 016 lands first, its format
version covers it; if 017 lands first, 016 must include `kind` in format version 1. Implement alongside 016.

**Note**: doc paths are low-entropy (an operator can list the allowlisted files), so `uri_hash` links reads of one
document without hiding which one from someone who has the file list. It hides the path from a log reader without
that list, which is what FR-014 asks.

## R12. Schema resources and the effective catalog (US2)

`SchemaDescribeTool::handle` builds `new Catalog(config('context-gate.classification'))` today. Spec 015 replaces this
with the merged catalog *(provisional, 015: `Catalog::effective()`)*. The schema resource calls the tool's handler,
so it follows whatever catalog the tool uses, and SC-006 holds by construction. 017 lands after 015 so that `secret`
columns hidden by a model declaration are also hidden in the resource (US2 scenario 3).

## R13. Audit and check (FR-016, FR-007)

`gate:audit` prints, after the tools, each enabled resource entry and prompt: name, kind, ability, fingerprint state
(`pinned`, `mismatch`, `missing`) and rule hashes (resources: the matching tool's contract and classification
hashes; prompts: a hash of the template). New readiness check `PromptCheck` fails for an invalid prompt (schema, load
check, unknown tool reference) naming it; `SettingsCheck` covers unknown entry names.
