# Specification Quality Checklist: Read-Only Runbooks

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-11-01
**Feature**: [spec.md](../spec.md)

## Content Quality

- [x] No implementation details beyond named interfaces, method names and reason codes
- [x] Focused on user value (console report, assistant health check) and privacy outcomes
- [x] Written for developers and reviewers (scenarios read as journeys)
- [x] All mandatory sections completed (user scenarios, requirements, success criteria)

## Requirement Completeness

- [x] No [NEEDS CLARIFICATION] markers remain
- [x] 22 functional requirements (FR-001 to FR-022), each testable
- [x] Success criteria are measurable (SC-001 to SC-006)
- [x] Success criteria are verifiable through automated tests (source scan, method-set assertion, fingerprint comparison)
- [x] Acceptance scenarios defined for all five user stories
- [x] Edge cases identified (throw, timeout, oversize, DB down, missing migrations table, ceiling, duplicate names)
- [x] Scope is bounded: no write, no HTTP, no dispatch, no preemptive timeout, no scheduling, no UI
- [x] Dependencies (specs 002, 003, 007) and assumptions identified

## Constitution Check

- [x] Principles I to X each addressed in the spec's Constitution Check section
- [x] Classification and ceiling stated for the new source (`internal`, ceiling `internal`) as required for new sources
- [x] Limits are code caps; configuration only lowers them
- [x] No new runtime dependency
- [x] Amendment need stated: none to the constitution; spec 007 key list and spec 002 source list need a change at planning

## Feature Readiness

- [x] Every FR maps to at least one user story or success criterion
- [x] User stories are prioritised (P1 to P2) and independently testable
- [x] No implementation detail leaks beyond the named contract surface

## Open Items for the Owner (not blocking the draft)

- [ ] Preemptive timeout: PHP cannot interrupt a blocking step without `pcntl`. The draft measures and reports `timeout`
      and relies on connection timeouts. Owner to confirm this limit is acceptable.
- [ ] Console runs while `runbook.<name>` is disabled (FR-013). Owner to confirm.
- [ ] Default limits in FR-016 (2.5 s, 512 bytes, cap 5 s, 2048 bytes, 20 steps, 8 KiB report, 30 s run). Owner to confirm.
- [ ] Console does not write a receipt (FR-015). Owner to confirm, since constitution IV names tool calls only.

## Notes

- Draft written on 2026-11-01 with the scope rules of constitution 1.1.0 and `CLAUDE.md` in view.
- Companion spec 019 (write flows) is blocked and needs a constitution amendment before any plan.
