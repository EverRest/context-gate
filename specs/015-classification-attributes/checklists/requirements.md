# Specification Quality Checklist: Classification Declared on the Model

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-11-01
**Feature**: [spec.md](../spec.md)

## Content Quality

- [x] Focused on developer and operator outcomes (classify at the change, fail CI on a gap)
- [x] Written as journeys; the declaration mechanism is left to the plan
- [x] All mandatory sections completed

## Requirement Completeness

- [x] No [NEEDS CLARIFICATION] markers remain (resolved 2026-11-01: allowlisted models only).
- [x] Requirements are testable and unambiguous
- [x] Precedence is deterministic and fail-closed (FR-003 to FR-005), justified by principle VI
- [x] Success criteria are measurable
- [x] Success criteria are technology-agnostic
- [x] All acceptance scenarios are defined
- [x] Edge cases are identified (shared tables, stale declarations, connections, pseudo-tables, inheritance)
- [x] Scope is clearly bounded
- [x] Dependencies and assumptions identified

## Feature Readiness

- [x] All functional requirements have clear acceptance criteria
- [x] Noted (plan decision, not failing): inheritance of declarations from a parent model is a default in Edge Cases, not a requirement; the plan must
  confirm it.
- [x] Feature meets measurable outcomes defined in Success Criteria
- [x] Constitution principles named for every Out of Scope item

## Notes

- FR-004 chooses "stricter class at runtime, FAIL in `gate:check`" over refusing every call. This differs from the
  `firewall.ceiling` rule (refused per call) because here the runtime result is already the safe one.
- Spec 016 receipts carry the classification hash defined in FR-011; spec 017 schema resources use the effective
  catalog.
