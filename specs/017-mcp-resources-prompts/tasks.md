# Tasks: MCP Resources and Prompts

**Input**: Design documents from `specs/017-mcp-resources-prompts/`

**Prerequisites**: plan.md, spec.md, research.md (R1–R13), data-model.md, contracts/resources-prompts.md. Spec 015 must
be implemented before the tasks marked **[015]** (effective catalog for schema resources). The receipt `kind` field
(T009–T010) is implemented together with spec 016 (**[016]**): whichever lands first, the 016 structured format
version 1 documents `kind`. The maintainer confirms the SC-001 wording (plan, spec issue 1) before T013.

**Tests**: REQUIRED (constitution IX). Every implementation task is preceded by a test task that must fail first.
*Characterization* tasks lock today's behaviour and pass when written. No test uses the network.

**Organization**: by user story. US1 and US4 are P1; US2 and US3 are P2. US4 (visibility, capabilities, pinning) is
foundational for the others and is built in Phase 2; its end-to-end checks are Phase 6.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: US1…US4 from spec.md
- Paths are relative to the repository root

---

## Phase 1: Setup

- [ ] T001 Write test in `tests/Unit/Settings/ConfigDefaultsTest.php`: `config/context-gate.php` has `resources.docs` and `resources.schema` with `enabled` false, `gate` and `fingerprint` null; `prompts` = `[]`; `resources_page_size` null
- [ ] T002 Edit `config/context-gate.php` to add `resources`, `prompts` and `resources_page_size` with comments (data-model Setting keys)
- [ ] T003 [P] Write test `tests/Unit/Pipeline/CapsResourcesTest.php`: `Caps::RESOURCE_PAGE` = 1000, `RESOURCE_LIST` = 10000, `PROMPT_ARGUMENT` = 1000
- [ ] T004 Edit `src/Pipeline/Caps.php` to add the three constants
- [ ] T005 [P] Write test `tests/Contract/ProtocolMethodsTodayTest.php` (*characterization*): with nothing configured, `initialize` capabilities equal `{"tools":{"listChanged":false}}`, and `tools/list` and `tools/call` answers are unchanged for a configured test tool (locks SC-001 for tools before the method map changes)

---

## Phase 2: Foundational (settings, receipts, pins, visibility, method map)

**Purpose**: everything the three stories share. No resource content and no prompt fill yet.

**Checkpoint**: receipts carry `kind`; pins and visibility are unit-tested; only the allowed methods are routed and
capabilities follow the caller.

- [ ] T006 [P] Write test `tests/Unit/Settings/SettingsValidatorResourcesTest.php`: a `resources` key other than `docs`/`schema` is a violation naming it; `prompts.<n>.class` that is not a `ContextGate\Server\Prompt` is a violation; `callers.<id>.resources` or `.prompts` naming an absent entry is a violation naming the key; `resources_page_size` 0 or 1001 is a violation; 1000 passes
- [ ] T007 Edit `src/Settings/SettingsSchema.php` and `src/Settings/SettingsValidator.php` for the keys and cross-checks of T006
- [ ] T008 [P] Write test `tests/Unit/Server/CallerListsTest.php`: `Caller::current()` reads `resources` and `prompts` lists by key like `tools`; non-strings are dropped; missing lists are empty
- [ ] T009 [P] [016] Write test `tests/Unit/Server/ReceiptKindTest.php`: every receipt has `kind`, default `tool`; `kind` `resource` with `uri_hash` writes both; without `uriHash` no `uri_hash` key; a sentinel path and argument never appear in the line
- [ ] T010 [016] Edit `src/Server/Receipt.php` (`string $kind = 'tool'`, `?string $uriHash = null`) and `src/Server/ResponseBuilder.php` (`receipt(..., kind:, uriHash:)`); edit `src/Server/Caller.php` for T008
- [ ] T011 [P] Write test `tests/Unit/Server/FingerprintKindsTest.php`: `ofResource('docs')` and `ofPrompt($p)` are stable; a change of description, mimeType or uriTemplate changes the resource pin; a change of name, description, schema, template or tools changes the prompt pin; a tool, resource and prompt with the same name have different pins
- [ ] T012 Edit `src/Server/Fingerprint.php` to add `ofResource()` and `ofPrompt()` over the members in data-model Fingerprint input; edit `src/Server/ReasonCode.php` to add `ResourcePinMismatch` and `PromptPinMismatch` (decision `hidden`)
- [ ] T013 [P] Write test `tests/Contract/ProtocolMethodsTest.php`: with nothing configured, `resources/list`, `resources/read`, `prompts/list`, `prompts/get`, `resources/templates/list`, `completion/complete` and `subscriptions/listen` each answer `-32601 The method [<m>] was not found.`, identical to an invented method name; `initialize` and `server/discover` advertise only `tools`; `ping` still answers
- [ ] T014 Edit `src/Server/GateServer.php`: own `protected array $methods` (research R2) and `resolvedCapabilities()` per caller via `Visibility` (research R9), fail closed to tools only on any exception
- [ ] T015 [P] Write test `tests/Unit/Server/VisibilityTest.php`: a resource entry is visible only when the gate, the entry, the pin and the caller list all allow it; a prompt additionally needs `valid` and every referenced tool visible; `enabled` false at the gate level hides everything; capabilities contain `resources` / `prompts` exactly when the matching list is non-empty
- [ ] T016 Create `src/Server/Visibility.php`, `src/Server/Resources/ResourceRegistry.php` (entries and pins only; enumeration in US1) and `src/Server/Prompts/PromptRegistry.php` (entries and pins only; load check in US3)
- [ ] T017 [P] Write test `tests/Unit/Policy/ToolPolicyPrefixTest.php`: `allows('resource:docs', …)` uses `context-gate.resources.docs.gate`; `allows('prompt:x', …)` uses `context-gate.prompts.x.gate`; a null gate denies; a tool named `resource:docs` cannot exist (ToolRegistry name rule)
- [ ] T018 Edit `src/Policy/ToolPolicy.php` for the prefixes
- [ ] T019 [P] Write test `tests/Unit/Server/SharedBudgetTest.php`: the `Budget` used by a resource read and by a tool call for the same caller are built by one factory with the same rate key, session key and day key (research R5)
- [ ] T020 Edit `src/Server/GateCallTool.php` to move `budget()` into a public static factory (in `src/Server/Admission.php`) used by tools and resources. No behaviour change; the existing suite is the regression test

---

## Phase 3: User Story 1 - Read allowlisted documentation as resources (Priority: P1)

**Goal**: list and read allowlisted docs as resources through the docs.read path.

**Independent Test**: allow one directory; only its files are listed; a read equals `docs.read` for the same file and writes a receipt.

### Tests for User Story 1

- [ ] T021 [P] [US1] Write test `tests/Unit/Server/Resources/ResourceUriTest.php`: `gate://docs/docs/guide.md` round-trips; a URI with `..`, `%2F`, `%5C`, double encoding, a different scheme or host, letter-case change of a denylisted name, or a Unicode look-alike parses to unknown or is refused by `DocsPathPolicy`; a schema URI maps `App/Models/Entry` to `App\Models\Entry` and only an exact allowlisted class matches
- [ ] T022 [US1] Create `src/Server/Resources/ResourceUri.php`
- [ ] T023 [P] [US1] Write test `tests/Unit/Server/Resources/DocsEnumerationTest.php` (fixture dir `tests/Fixtures/app/docs`): only regular files under allowlisted paths that pass `DocsPathPolicy` are listed, sorted; a denylisted file (`.env`-like name) and a symlink leading outside are absent; enumeration stops at `RESOURCE_LIST` (lowered through a test seam); a file added after the first list appears on the next one
- [ ] T024 [US1] Add enumeration to `src/Server/Resources/ResourceRegistry.php`
- [ ] T025 [P] [US1] Write test `tests/Contract/Resources/ListResourcesTest.php`: a caller with `docs` sees the `resources` capability and each readable file once with uri, name, title, description and `application/json`; pages hold `resources_page_size` entries and the last has no `nextCursor` (FR-015)
- [ ] T026 [US1] Create `src/Server/Resources/GateListResources.php` (vendor `CursorPaginator`) and register it in `src/Server/GateServer.php`
- [ ] T027 [P] [US1] Write test `tests/Contract/Resources/ReadDocTest.php`: reading a listed URI returns `contents[0].text` byte-equal to `tools/call docs.read` `content[0].text` for the same file (SC-006), marked untrusted; a receipt with `kind` resource, `tool` docs and `uri_hash` is written and holds no path
- [ ] T028 [P] [US1] Write test `tests/Contract/Resources/ReadDocRefusalTest.php`: a denylisted path, a path outside the allowlist, a link escape, a missing file and an invented URI all answer `-32602 Resource not found.` byte-identically, and each receipt records its real reason (`source.refused` with step, `source.missing`, `policy.hidden`); a document with a canary answers `Request failed. Reference: …` and records `canary.hit` (US1 scenarios 3–4)
- [ ] T029 [US1] Edit `src/Server/FirewallStep.php` (optional renderer) and create `src/Server/Resources/GateReadResource.php` (plan Read path; research R5, R6); add the JSON-RPC error helpers to `src/Server/ResponseBuilder.php`; register the handler in `src/Server/GateServer.php`
- [ ] T030 [P] [US1] Write test `tests/Contract/Resources/SharedBudgetTest.php` (SC-007): with a lowered session byte limit, tool calls that use it up make the next resource read answer `Request refused.` with receipt `budget.session`, and the reverse; reads count toward the per-minute limit and a read over it records `rate.limited`; total served bytes never exceed the session limit across a mix
- [ ] T031 [P] [US1] Write test `tests/EndToEnd/StdioResourceReadTest.php`: over stdio, a `resources/read` writes only protocol messages to stdout and the receipt to the log channel

**Checkpoint**: US1 is independently testable with docs only.

---

## Phase 4: User Story 2 - Read model schemas as resources (Priority: P2)

**Goal**: one URI per allowlisted model, content equal to `schema.describe`.

**Independent Test**: the list holds one URI per allowlisted model; a read equals the `schema.describe` rows.

### Tests for User Story 2

- [ ] T032 [P] [US2] Write test `tests/Contract/Resources/SchemaResourceTest.php`: one URI per allowlisted model; a read's text is byte-equal to `schema.describe` for that model; a non-allowlisted model's URI answers `Resource not found.`; a model whose table is missing answers `Request failed. Reference: …` with `schema.missing`
- [ ] T033 [P] [US2] [015] Write test `tests/Contract/Resources/SchemaSecretColumnTest.php`: a column `secret` in the effective catalog (declared on the model, spec 015) is absent from the resource and counted in `hidden_columns`, as in `schema.describe`
- [ ] T034 [US2] Add schema items to `src/Server/Resources/ResourceRegistry.php` and the schema branch to `src/Server/Resources/GateReadResource.php` (reuses `SchemaDescribeTool` and `SchemaItemsContract`)

---

## Phase 5: User Story 3 - Host-defined prompts with validated arguments (Priority: P2)

**Goal**: host prompts listed and filled, arguments validated like tool arguments, never data.

**Independent Test**: a valid argument fills the template; an invalid one answers `Invalid arguments: …` and writes a receipt.

### Tests for User Story 3

- [ ] T035 [P] [US3] Write test `tests/Unit/Server/Prompts/PromptLoadTest.php` (fixtures `tests/Fixtures/Prompts/*.php`): a schema with a non-string property, a property without `maxLength`, `maxLength` above `PROMPT_ARGUMENT`, a placeholder for an undeclared property, a template holding a canary, a template the redactor changes, an unknown tool reference, or an invalid name make the prompt invalid with a message naming it
- [ ] T036 [US3] Create `src/Server/Prompt.php` (`@api`) and add the load check to `src/Server/Prompts/PromptRegistry.php` (research R7)
- [ ] T037 [P] [US3] Write test `tests/Unit/Readiness/Checks/PromptCheckTest.php`: an invalid prompt gives FAIL naming it; valid prompts PASS
- [ ] T038 [US3] Create `src/Readiness/Checks/PromptCheck.php` and register it in `src/Readiness/CheckRegistry.php`
- [ ] T039 [P] [US3] Write test `tests/Contract/Prompts/ListPromptsTest.php`: a visible prompt is listed with name, title, description and `arguments` (name, description, required) derived from the schema; a prompt referring to a tool the caller cannot see is absent (US3 scenario 5)
- [ ] T040 [P] [US3] Write test `tests/Contract/Prompts/GetPromptTest.php`: valid arguments return the filled text and a receipt with `kind` prompt (scenario 1); a schema failure answers `-32602 Invalid arguments: …` and records `input.invalid` (scenario 2); a canary in an argument answers `Request refused.` and records `canary.hit` before any fill (scenario 3); a pseudonym string is inserted as sent and the pseudonym store is never called (scenario 4, a counting fake `tests/Fixtures/CountingPseudonymizer.php`); a hidden prompt and an invented name answer `Prompt not found.` identically (scenario 5); no source, tool handler or query runs (a counting fake tool)
- [ ] T041 [US3] Create `src/Server/Prompts/GateListPrompts.php` and `src/Server/Prompts/GateGetPrompt.php` (plan Prompt path); register both in `src/Server/GateServer.php`

---

## Phase 6: User Story 4 - Visibility, capabilities and pinning (Priority: P1)

**Goal**: end-to-end proof of deny by default, per-caller capabilities, pins and the fingerprint command.

**Independent Test**: nothing configured advertises only tools; caller A sees the prompts capability and B does not; a changed template hides the prompt until re-pinned.

### Tests for User Story 4

- [ ] T042 [P] [US4] Write test `tests/Contract/VisibilityCapabilitiesTest.php`: scenario 1 (nothing configured → only tools); scenario 2 (resource for A only: B's `initialize` has no `resources`, B's list and read answer as an unknown method, B's read receipt records `policy.hidden`); scenario 4 (`enabled` false → everything absent for every caller)
- [ ] T043 [P] [US4] Write test `tests/Contract/PinMismatchTest.php`: a changed prompt template, a changed resource description (test subclass seam) or a missing fingerprint hides the entry from lists for every caller; a get/read records `prompt.pin_mismatch` / `resource.pin_mismatch` (scenario 3, SC-005)
- [ ] T044 [P] [US4] Write test `tests/Unit/Console/FingerprintResourcePromptTest.php`: `gate:fingerprint docs --resource` and `gate:fingerprint <prompt> --prompt` print the fingerprint and nothing else and exit 0; an unknown name exits 1 (scenario 5, FR-013)
- [ ] T045 [US4] Edit `src/Console/FingerprintCommand.php` for `--resource` and `--prompt`
- [ ] T046 [P] [US4] Write test `tests/Contract/AuditResourcesPromptsTest.php`: `gate:audit` lists enabled resource entries and prompts with kind, ability, fingerprint state and rule hashes, and no path, template text or argument (FR-016)
- [ ] T047 [US4] Edit `src/Readiness/Audit.php` and `src/Console/AuditCommand.php` for T046

---

## Phase 7: Polish and Cross-Cutting

- [ ] T048 [P] Write test in `tests/Contract/PrivacySuiteTest.php` (SC-003, SC-004): resource reads of fixture docs and schemas seeded with `tests/Fixtures/SeededPii.php` values and canaries return none of them; no prompt result contains a seeded value; no receipt holds a path, an argument or content
- [ ] T049 Update `docs/privacy.md` (resources and prompts follow the firewall; prompts carry no data; no completion, no subscriptions, no push; receipt `kind` and `uri_hash` and the low-entropy note from research R11; reason codes `resource.pin_mismatch`, `prompt.pin_mismatch`)
- [ ] T050 [P] Update `docs/configuration.md` (resources, prompts, callers lists, `resources_page_size`) and `docs/extending.md` (writing a prompt, quickstart steps)
- [ ] T051 [P] Update `README.md` feature list and `CHANGELOG.md` under Unreleased (resources and prompts; removed vendor methods `completion/complete`, `subscriptions/listen`, `resources/templates/list`; receipts always carry `kind`)
- [ ] T052 Note in `specs/011-quality-gates/` follow-up list (or its tasks when planned) that the security scope includes `src/Server/Resources/` and `src/Server/Prompts/` (spec assumption); no code
- [ ] T053 Confirm `src/Server/GateServer.php`, `src/Server/ResponseBuilder.php` and every new file stay within `scripts/check-file-length.php`
- [ ] T054 Run `composer style`, `composer stan`, `composer length` and `composer test`; read each exit code; all must be 0

---

## Dependencies and Execution Order

- Phase 1 before everything; T005 (characterization) before T014 changes the method map.
- Phase 2 before all stories. T009–T010 go with spec 016.
- US1 (Phase 3) after Phase 2. US2 after US1's read handler (T029) and spec 015 (T033). US3 after Phase 2 only and
  may run in parallel with US1. US4 (Phase 6) after US1 and US3, since it checks them end to end.
- Within a story, each test task runs and fails before its implementation task.
- **[P]** tasks touch different files.

**Task count**: 54 (Setup 5, Foundational 15, US1 11, US2 3, US3 7, US4 6, Polish 7).
