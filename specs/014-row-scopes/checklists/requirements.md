# Specification Quality Checklist: Row Scopes per Caller

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-11-01
**Feature**: [spec.md](../spec.md)

## Content Quality

- [x] Focused on user value (a federation sees only its own rows; support sees all, explicitly)
- [x] Written for non-technical stakeholders (scenarios read as journeys)
- [x] All mandatory sections completed
- [x] No implementation details (no query or storage design)

## Requirement Completeness

- [x] No [NEEDS CLARIFICATION] markers remain (resolved 2026-11-01: host resolves per call, fail closed).
- [x] Requirements are testable and unambiguous
- [x] Success criteria are measurable
- [x] All acceptance scenarios are defined
- [x] Edge cases are identified
- [x] Scope is clearly bounded (rows only; no value hierarchies; no external policy engine)
- [x] Dependencies and assumptions identified. Noted, not failing: spec 010 (caller roles) still reserves a scope-attributes key that this spec no longer uses for values; the 010 and 014 plans must agree whether to drop it.

## Feature Readiness

- [x] All functional requirements have clear acceptance criteria
- [x] Feature meets measurable outcomes defined in Success Criteria
- [x] Ready for planning (clarification resolved 2026-11-01). Noted: the 010 plan must settle the reserved scope-attributes key (see above).

## Notes

- FR-002 (every contract declares a scope or unscoped) is breaking for existing contracts; accepted, no users
  before version 010.
- FR-016 forbids hashes of scope values in receipts because the value set is small and a hash is reversible.
