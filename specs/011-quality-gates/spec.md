# Feature Specification: Quality Gates and Maintenance Hygiene

**Feature Branch**: `011-quality-gates`

**Created**: 2026-11-01

**Status**: Clarified

**Input**: Owner request: "improvements and features the package needs". This spec covers two things. First, the
security tests must prove that they catch bugs: a review ran 75 mutations of the security tests and about 12
survived, and CI has no mutation testing and no coverage floor. Second, maintenance hygiene: there are no users
before version 010, so the backward-compatibility scaffolding is noise, and the source comments point at spec
documents that are not shipped.

## Clarifications

### Session 2026-11-01

- Q: What is the minimum mutation score for the security scope: 90% of mutants, or 100% with an explicit ignore list? (FR-003) → A: 90% for the security scope, enforced in CI. No ignore list.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Security tests must kill mutants (Priority: P1)

A maintainer changes code on the security path: the pipeline, the privacy classes, HTTP authentication, or the path
and egress policies of the sources. CI changes that code in small ways (mutants) and runs the tests against each
mutant. If too many mutants survive, the merge is blocked.

**Why this priority**: A security test that passes against broken code protects nothing. The review showed that
about one mutant in six survives today.

**Independent Test**: Lower the threshold to a value above the current score; the CI job fails and names the
surviving mutants. Restore the threshold; the job passes.

**Acceptance Scenarios**:

1. **Given** the security scope and the minimum score, **When** the mutation job runs and the score is at or above the
   minimum, **Then** the job passes.
2. **Given** a change that adds an untested branch to the security scope, **When** the mutation job runs and the score
   drops below the minimum, **Then** the job fails and lists each surviving mutant by file and line.
3. **Given** the about 12 mutants that survived the review, **When** this feature is done, **Then** each is killed by
   a test.

---

### User Story 2 - A coverage floor (Priority: P1)

CI measures line coverage of the package source and fails when it drops below a floor. The coverage job that is
commented out today runs again, and the command it calls exists.

**Why this priority**: Mutation testing only covers the security scope. A floor keeps the rest of the code from
losing its tests without anyone noticing.

**Independent Test**: Delete one test file in a branch; the coverage job fails and prints the measured value and the
floor.

**Acceptance Scenarios**:

1. **Given** coverage at or above the floor, **When** the coverage job runs, **Then** it passes and writes a short
   summary to the job summary.
2. **Given** coverage below the floor, **When** the coverage job runs, **Then** it fails and prints both numbers.
3. **Given** a fresh clone, **When** a maintainer runs the coverage command named in the CI job, **Then** the command
   exists and produces the report the floor check reads.

---

### User Story 3 - A fixed finding stays fixed (Priority: P1)

When a review finding is fixed, the fix keeps a test that fails if the fix is undone. The finding and its guarding
test are recorded together, so a later refactor cannot remove the test without someone noticing.

**Why this priority**: Review findings are the most expensive bugs to find. Losing the test that guards one means
paying for the same finding twice.

**Independent Test**: Undo one recorded fix by hand; its recorded test fails. Delete a recorded test; the regression
check fails and names the finding.

**Acceptance Scenarios**:

1. **Given** a recorded finding, **When** its fix is reverted, **Then** the recorded test fails.
2. **Given** a recorded finding whose test no longer exists, **When** CI runs, **Then** the check fails and names the
   finding.
3. **Given** a new finding is fixed, **When** the fix is merged, **Then** the record lists the finding and its test.

---

### User Story 4 - Remove backward-compatibility scaffolding (Priority: P2)

The owner confirmed there are no users before version 010. The changelog loses its "Breaking" section and its
"since superseded" notes, `docs/upgrading.md` is deleted with every link to it, and the container bindings that exist
only so that old concrete class names still resolve are removed.

**Why this priority**: Compatibility text for users who do not exist costs review time and hides the real rules.

**Independent Test**: Search the shipped files for links to the upgrading page and for "superseded"; none is found.
Bind a custom implementation to an extension interface; it is used. Bind one to the old concrete class name only; the
interface still resolves to the package default.

**Acceptance Scenarios**:

1. **Given** the changelog, **When** it is read, **Then** the unreleased entry lists the current behaviour once, with
   no "Breaking" section and no "superseded" note.
2. **Given** the documentation, **When** it is searched, **Then** no page links to an upgrading page and the upgrading
   page does not exist.
3. **Given** a host binds its own implementation to an extension interface, **When** the gate resolves it, **Then**
   the host implementation is used.
4. **Given** a host binds a class only to an old concrete class name, **When** the gate resolves the interface,
   **Then** the package default is used, and the extension documentation says so.

---

### User Story 5 - Source comments state the rule, not a spec number (Priority: P2)

A reader of the shipped source sees the rule itself in each comment, such as "a hidden tool answers like an unknown
tool", not "FR-004" or "spec 007 R9". The `specs/` directory is not in the distribution archive, so a spec number in a
shipped comment points nowhere. Spec IDs stay in tests and in specs.

**Why this priority**: About 250 references in shipped comments point to files a package user cannot open.

**Independent Test**: Run the reference check on `src/`; it reports zero references. Add one comment with a spec ID;
the check fails and names the file and line.

**Acceptance Scenarios**:

1. **Given** the shipped source, **When** the reference check runs, **Then** no comment holds a spec ID, requirement
   ID, research ID, task ID or decision ID.
2. **Given** a comment that held a reference, **When** it is rewritten, **Then** it states the rule in plain words and
   keeps its meaning.
3. **Given** a test or a spec file with a spec ID, **When** the reference check runs, **Then** it is not reported.

---

### Edge Cases

- A mutant that cannot be killed because it does not change behaviour (an equivalent mutant): there is no ignore list.
  It stays in the score; the 90% minimum leaves room for it. If it blocks a change, the code is changed so the mutant
  no longer exists, or the test is strengthened.
- A mutant that only times out: it counts as killed, as long as the timeout is the test's own and not the job's.
- The coverage tool is missing from the runner: the job fails; it never passes with no measurement.
- The mutation job runs on a change that touches no file in the security scope: it still runs, so the score cannot
  drift through a change of the scope list.
- Tests that need a Redis service (opt-in through an environment variable): they stay opt-in. The mutation and
  coverage jobs may provide the service, but `composer test` never needs it.
- A comment whose meaning depended only on the spec reference (for example "see FR-016"): it is rewritten with the
  rule, or deleted if the code already says the same.
- A false positive of the reference check (for example a register name or a hash fragment that looks like an ID): the
  pattern is narrowed; no per-line opt-out exists.
- The README has an uncommitted change by another contributor at the time of writing: the README link to the upgrading
  page is removed only after that change lands, in the same feature.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: CI MUST run mutation testing over the security scope on every push and pull request, as its own job.
- **FR-002**: The security scope MUST be one explicit list kept in the repository. It MUST include at least the
  pipeline stages, the privacy classes (pseudonyms, redaction), the HTTP authentication, origin and refusal-throttle
  middleware, the call path that decides visibility, admission and arguments, and the path and egress policies of the
  sources (document paths, URL guard, address ranges, pinned client).
- **FR-003**: The mutation job MUST fail when the score over the security scope is below the minimum score. The
  minimum is 90% of mutants over the security scope, enforced in CI. No mutant is excluded from the score.
- **FR-004**: The minimum mutation score and the coverage floor MUST be defined in the repository, MUST NOT be lowered
  by the CI job or by an environment variable, and MAY only be raised by a reviewed change. Lowering either needs a
  written reason in the change.
- **FR-005**: A failing mutation job MUST list each surviving mutant by file, line and mutation type, and MUST NOT
  print any fixture secret or canary value.
- **FR-006**: The mutation score MUST NOT use an ignore list: every mutant in the security scope counts, equivalent
  mutants included. The job MUST fail if the repository holds any file or setting that excludes a mutant from the
  score.
- **FR-007**: CI MUST run a coverage job that measures line coverage of `src/` and fails when it is below the
  coverage floor. The command the job calls MUST exist in the repository.
- **FR-008**: Each fixed review finding MUST be recorded with the test that guards it. CI MUST fail when a recorded
  test does not exist or does not run.
- **FR-009**: Each recorded test MUST fail when its fix is undone. The plan decides how this is checked; a hand check
  at the time of recording is the minimum.
- **FR-010**: The about 12 mutants that survived the review MUST be killed and recorded under FR-008 before the
  minimum score is enforced.
- **FR-011**: `composer test` MUST stay free of network use and MUST NOT run mutation testing or coverage. Mutation and
  coverage run in their own CI jobs, like the install matrix.
- **FR-012**: The changelog MUST describe the current behaviour once per change, with no "Breaking" section and no
  "superseded" note.
- **FR-013**: `docs/upgrading.md` MUST be deleted, and every link to it in shipped documentation MUST be removed.
- **FR-014**: The container MUST bind each extension interface directly to its default implementation. Bindings that
  exist only so that an old concrete class name resolves MUST be removed, and the extension documentation MUST say that
  the interface is the only extension point.
- **FR-015**: Comments in `src/` MUST NOT reference spec, requirement, success-criterion, research, task or decision
  IDs. Each such comment MUST state the rule in plain words or be removed.
- **FR-016**: A check MUST run with the other gates and fail when a comment in `src/` holds such an ID, naming the
  file and line. Tests and specs are not checked.
- **FR-017**: No rule, limit, stage order or reason code changes in this feature. The hygiene work changes comments,
  documentation and container bindings only.

### Key Entities

- **Security scope**: the list of source paths whose mutants count toward the minimum score.
- **Mutation score**: killed mutants divided by all mutants in the scope (none excluded).
- **Finding record**: a review finding, the date it was fixed, and the test that guards it.
- **Coverage floor**: the lowest line coverage of `src/` that CI accepts.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: The mutation score over the security scope is at or above the minimum on the default branch, and every
  change that drops it below the minimum is blocked (100% of cases).
- **SC-002**: All mutants that survived the review are killed (0 survivors from that set).
- **SC-003**: Line coverage of `src/` is at or above the floor on the default branch.
- **SC-004**: Every recorded finding has a test that exists and runs (100%).
- **SC-005**: Zero spec, requirement, research, task or decision IDs remain in comments under `src/`.
- **SC-006**: Zero links to an upgrading page and zero "superseded" notes remain in shipped documentation.
- **SC-007**: `composer test` uses the network in 0 runs.

## Out of Scope

- **A configuration switch that turns the mutation or coverage gate off** (principle VI, Config Narrows, Never Widens
  Protections, applied to the project's own gates): the thresholds can only be raised by a reviewed change.
- **Mutation testing inside `composer test`** (principle IX: `composer test` must stay fast and network-free; the
  mutation run is a separate job).
- **A mutation-testing or coverage tool as a runtime dependency** (principle X, Minimal Dependencies): it is a dev
  dependency only.
- **New rules, limits or reason codes**: this feature tests and documents existing rules (principle VI and the
  governance rule that a rule change goes into the spec first).
- **Making `Redactor`, `Pseudonymizer` and `ToolPolicy` final again**: not requested; it may be a later hygiene item.
- **Removing spec IDs from tests and specs**: they are the traceability links and are not shipped.

## Assumptions

- A mutation-testing tool and a coverage driver are added as dev dependencies. Justification: the constitution
  requires that each rule has a test that fails without its implementation (IX); only mutation testing checks that at
  scale. Neither is in the distribution archive.
- The coverage floor default is 92% of lines, the value the disabled CI job already names. The disabled job also calls
  a floor script that does not exist yet; this feature adds it.
- The mutation job runs on one PHP version (8.3, locked dependencies), like `composer stan` and `composer style`.
- The finding records live in the repository next to the tests, and each recorded test name or annotation carries the
  finding ID (spec IDs are allowed in tests).
- The reference check is a small repository script run by the same CI job as the file-length check; it adds no
  dependency.
- The README is edited for the upgrading link only after the contributor's uncommitted README change is committed.

## Amendments (owner decisions, 2026-11-01)

- OPEN (2026-11-01): installing infection/infection and pcov needs explicit owner approval; no decision recorded yet.
- OPEN (2026-11-01): the gate:check spec-reference wording question; no decision recorded yet.
- 2026-11-01: implementation order 011, 008, 009, then 010 and 015 in parallel, 014, 012, 013, 016, 017 (see decisions D9 and D12).
