# Research: Release Readiness

## R1. One checks registry for audit and doctor

- **Decision**: a `Check` interface with `name(): string`, `run(Context $ctx): CheckResult` and `fixHint(): string`.
  `CheckResult` has `status` (pass, fail, warn), `subject` (the setting key or file path), and `message`.
  Every check from specs 002–005 is registered once. `gate:check` runs all checks and exits non-zero on any
  `fail`. `gate:audit` runs a subset (the exposure checks) through the same registry.
- **Rationale**: the audit and the doctor cannot disagree about what is exposed or what is valid.
- **Alternatives considered**: separate code for each command (drift risk).

## R2. Checks list

Configuration and caps (spec 002 FR-022): each limit ≤ its code cap.
Catalog (spec 002 FR-001, FR-006): every contract column classified; no draft markers.
Contracts (spec 002 FR-004, FR-007; spec 003): no `secret` field; relation depth 1.
Canaries (spec 002 FR-017; spec 006 FR-008): when enabled, each value non-empty.
Environment (FR-009): `ext-intl` and `symfony/yaml` loadable.
Origins (spec 005 FR-014; FR-010 here): exact origin format.
Tokens (spec 005 FR-011): `gate_access_tokens`, `gate_token_holders` and `gate_token_issuers` tables exist; abilities in the package
set (checked on the existing tokens, not on values).
Transport (spec 005 FR-013; FR-012 here): production with stdio-only is a failure.
URL allowlist (spec 004): host entries lowercase, no scheme.
Install state: `config/context-gate.php` present; `gate:install` run.

- **Verification point**: the schema check depends on a database connection (edge case). It is a warning when
  no enabled tool needs the schema.

## R3. Audit output

- **Decision**: for each enabled tool, the audit reads the tool registry, the contract (field classes only),
  and the rule hashes computed at boot (spec 002 R11). It prints names and classes; no field value, no pseudonym,
  no canary, no token. `--json` emits the same structure.
- **Rationale**: FR-004. The audit never executes a tool, so it cannot read rows.

## R4. Installation test

- **Decision**: a script `scripts/install-test.sh <laravel-version>` creates a fresh skeleton in a temporary
  directory with `composer create-project laravel/laravel`, points the skeleton at the local package path, runs
  `gate:install`, `gate:check` and the spec 001 quickstart commands, and deletes the directory. It runs in CI as
  a matrix for `12.*` and `13.*`. It is not committed as generated output.
- **Verification point**: the pinned Laravel skeleton versions are checked before the release.
- **Alternatives considered**: a Testbench-only test (does not prove a fresh application installs).

## R5. Release workflow

- **Decision**: adapted from watcher's `release.yml`: archive name `context-gate-<tag>.tar.gz`, changelog guard
  (the version heading must exist), version constant guard. The workflow has a `workflow_dispatch` input
  `publish` defaulting to `false`. Without `publish: true`, it builds and uploads the artifact only.
- **Approval**: `publish: true` requires a manual run by the maintainer (FR-020). The SECURITY.md placeholder
  fails the release check (Assumptions), so publication is blocked until the reporting address is filled in.
- **Alternatives considered**: tag-triggered publication (too easy to trigger by accident).

## R6. Documentation structure

- **Decision**: `docs/installation.md`, `docs/configuration.md` (generated table from the config file keys,
  checked by a test, SC-004), `docs/tokens.md`, `docs/privacy.md`, `SECURITY.md`. The configuration reference is
  checked in a test that parses `config/context-gate.php` keys and compares them with the document.
- **Rationale**: FR-015 and SC-004 need an automatic check; manual lists drift.

## R7. Version constant and changelog

- **Decision**: `src/ContextGate.php` holds `const VERSION = '0.1.0'`; the changelog heading `## [0.1.0]`. A test
  compares both (FR-018, SC-006).

## R8. Placeholder reporting address

- **Decision**: `SECURITY.md` contains the placeholder `<REPORTING_ADDRESS>`. The release check fails while the
  placeholder string is present (Assumptions).
