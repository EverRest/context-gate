# Contract: readiness commands and release workflow

## Command: gate:audit

```text
php artisan gate:audit [--json]
```

Text output: one block per enabled tool (name, risk, ceiling, field classes, contract, rule hashes), then
`Not enabled:` with names. JSON: `{"tools": [AuditEntry...], "not_enabled": ["name", ...]}`. Exit 0. Read-only.

## Command: gate:check

```text
php artisan gate:check [--json]
```

Each line: `PASS|FAIL|WARN <subject>: <message>`; a FAIL line is followed by `fix: <hint>`. Exit 0 only when no FAIL.
No value appears in any line (FR-014).

## Release workflow (`.github/workflows/release.yml`)

Inputs:

- `tag` (string, required): version such as `v0.1.0`.
- `publish` (boolean, default `false`): publication only when `true`.

Steps:

1. Changelog guard: fails when `## [0.1.0]` is missing (FR-019).
2. Version guard: fails when `src/ContextGate.php` VERSION differs from the tag.
3. Placeholder guard: fails while `<REPORTING_ADDRESS>` is in `SECURITY.md` (R8).
4. Archive: `git archive --prefix="context-gate-<tag>/"` into `context-gate-<tag>.tar.gz` with provenance attestation.
5. Upload the artifact to the workflow run.
6. If `publish` is `true`: create the GitHub release. Otherwise stop here (FR-020).
