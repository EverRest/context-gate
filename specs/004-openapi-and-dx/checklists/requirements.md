# Specification Quality Checklist: OpenAPI Source and Developer Tooling

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-10-30
**Feature**: [spec.md](../spec.md)

## Content Quality

- [x] Focused on user value (safe contract reading, reviewed catalog, visible preview)
- [x] Written for non-technical stakeholders (scenarios read as journeys)
- [x] All mandatory sections completed

## Requirement Completeness

- [x] No [NEEDS CLARIFICATION] markers remain
- [x] Requirements are testable and unambiguous
- [x] Success criteria are measurable
- [x] All acceptance scenarios are defined
- [x] Edge cases are identified
- [x] Scope is clearly bounded (query catalog, audit and check completion, HTTP deferred)
- [x] Dependencies and assumptions identified

## Feature Readiness

- [x] All functional requirements have clear acceptance criteria
- [x] Feature meets measurable outcomes defined in Success Criteria

## Notes

- Decisions relied on: D14, D22, D29 in `/Users/pavlo/Desktop/context-gate-roadmap.html`.
- Network fetching has an explicit security review point at the planning stage (research must document the
  DNS pinning approach and test the rebinding case).

## Quickstart run (T058, batch 004-QS)

Run on 2026-11-01 on a fixture application, `tests/Fixtures/Quickstart`: models Order and User, the `orders.status`
tool with its contract, `openapi.endpoints` enabled with `sources.openapi.paths = ['api/']`, and `api/openapi.json`.
A Testbench-style runner boots the package on that base path (sqlite in memory, config set from environment
variables, the caller is the OS user, and a policy ability is defined). Nothing reads `.env` and no network is used.

- [x] Section 1 (local OpenAPI document): PASS for an example inside a response schema. `gate:preview openapi.endpoints
  --args='{"path":"api/openapi.json"}'` exit 0 printed two rows (POST /orders, GET /orders/{id}) with their schemas and
  no example; the seeded email appears nowhere. With `examples: redacted` the example is present and its email reads
  `[EMAIL]`. OPEN: an example placed as a Media Type `examples` object is absent in both modes (exit 0; no example and
  no email in the output), so `redacted` changes nothing for that placement. FR-003 names `examples`. Found, not fixed.
- [x] Section 2 (network refusals): PARTIAL, two parts. (a) PASS through the CLI: with an empty allowlist,
  `gate:preview openapi.endpoints --args='{"url":"https://docs.example.com/openapi.json"}'` exit 1 printed
  `Refused: egress.denied.`. (b) PASS through a seam script only: the real OpenApiSource, UrlGuard and PinnedClient with a
  fake resolver and a fake transport. 169.254.169.254 gave `egress.denied`, a 302 gave `source.redirect`, and a public
  address with a 200 JSON body was accepted (control). Not run through the CLI: OpenApiEndpointsTool hard-codes the real
  DNS resolver and HTTP transport, so the CLI would resolve names over the network.
- [x] Section 3 (catalog draft): PASS. `gate:classify` exit 0 printed `Wrote app/Gate/classification.php as a draft`.
  The draft has `'email' => 'personal', // suggested`, `'password' => 'secret', // suggested`, and every other column
  `'secret', // needs decision`. A second run exit 1 printed `app/Gate/classification.php already exists. Use --force`,
  and the file's md5 did not change.
- [x] Section 4 (preview): PASS. `gate:preview orders.status --args='{"status":"paid"}'` exit 0 printed
  `[{"id":1,"status":"paid","total":1200}]`, then `Dropped:` and `  notes  field.unclassified`. The log line carried
  `"source":"preview"`. The fixture's `notes` column has no catalog entry, which is what produces the Dropped line. The
  `user_id` column the tool also returns is removed by the contract with no Dropped line.

Gates on the branch: `composer test` exit 0 (729 tests, 3 skipped), `composer stan` exit 0, `composer style` exit 0,
`composer length` exit 0.
