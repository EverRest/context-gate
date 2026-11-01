# Implementation Plan: Read-Only Runbooks

**Branch**: `018-readonly-runbooks` | **Date**: 2026-11-01 | **Spec**: [spec.md](spec.md)

## Cross-spec tasks (from review 018-REV, not yet applied)

- [ ] X001 Edit `specs/007-configuration/spec.md` to add `runbooks` to its valid top-level configuration keys (unknown keys are `config.invalid`); record it in its Amendments section. Spec 018 FR-002.
- [ ] X002 Edit `specs/002-context-firewall/spec.md` (Amendments section) and `specs/002-context-firewall/data-model.md` to add the runbook source (`runbook.<name>`, classification `internal`, ceiling `internal`). Spec 018 FR-011.
