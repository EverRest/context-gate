# context-gate

Laravel package (Laravel 12 and 13, PHP 8.2–8.5): an MCP gateway that gives AI assistants only the data a
contract allows. Specs are in `specs/`; the roadmap brief is `specs/*/spec.md`. Constitution: `.specify/memory/constitution.md` (v1.1.0).

## Workflow

- Spec-driven: specify, clarify, plan, tasks, implement. Implement follows `specs/<feature>/tasks.md` in order.
- TDD: write the failing test first (constitution IX). Do not write implementation without a failing test.
- Every decision that changes a rule goes into the spec first; the code follows the spec.

## Gates

Run before any hand-off and before any commit:

- `composer test` (PHPUnit)
- `composer stan` (PHPStan, level 8)
- `composer style` (Pint, check mode)
- `composer length` (file length limit)

Read the exit codes, not the tail of the output. All gates must exit 0.

## Git

- Commit only when the user explicitly asks. Do not push unless asked.
- Never skip hooks (`--no-verify`), never rewrite history, never change git config.
- Commit messages: English, focused on why. No `Co-Authored-By` trailer.

## Hard rules from the constitution

- Never return a value that a contract does not allow; never log a value (receipts hold metadata and hashes only).
- stdout belongs to the protocol only; diagnostics go to stderr.
- Limits and stage order are in code; configuration can only lower a limit or select within caps.
- No network calls in tests. No new runtime dependency without a justification in the spec.
- Do not read or print `.env` or any real secret. Fixture secrets are fake and named `FAKE_FIXTURE_ONLY`.

## Scope

Read-only in MVP. No write tools, no Lumen, no OAuth flow until after 1.0. Publication to Packagist or a GitHub
release happens only after the maintainer's explicit approval.
