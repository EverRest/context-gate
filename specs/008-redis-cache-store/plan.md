# Implementation Plan: Redis as the Tested Cache Store

**Branch**: `008-redis-cache-store` | **Date**: 2026-11-01 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/008-redis-cache-store/spec.md`

## Summary

Makes Redis the tested gate store. `gate:check` gains one store check (driver class, probe, eviction policy, empty
prefix). Every key moves under `context-gate:` through one helper. A store error becomes `store.unavailable`
everywhere (tool call, firewall, pseudonyms, HTTP throttle) instead of `handler.failed`, `pseudonym.keyfail` or
`pseudonym.unknown`. The existing opt-in multi-process suite (`tests/Concurrency`, file store) gains a Redis mode, the
refusal-ledger and week/session pseudonym cases, exact assertions and a TTL sweep, and runs in a new CI job with a
Redis service and a PHP-FPM check. No runtime dependency.

## Technical Context

**Language/Version**: PHP 8.2+ (`composer.json` `php: ^8.2`).

**Primary Dependencies**: `laravel/framework` ^12 || ^13 cache and Redis components (host-provided). No new
dependency. CI uses the native `phpredis` extension (already installed by `setup-php`); Predis is not added (FR-014).

**Storage**: the host cache store named by `cache_store`. No table.

**Testing**: PHPUnit via `composer test` (paratest); Orchestra Testbench; mocked caches in unit tests. Opt-in:
`CONTEXT_GATE_CONCURRENCY=1` (existing gate) plus `CONTEXT_GATE_STORE=redis`; `CONTEXT_GATE_FPM_TEST=1` for FPM.

**Target Platform**: HTTP under PHP-FPM (m-seq) and stdio.

**Project Type**: Laravel package.

**Performance Goals**: no added round trip on the call path; the probe runs only in `gate:check`.

**Constraints**: fail closed on store error (FR-007); no exception message or connection detail in any output
(constitution VII); `composer test` opens no socket (constitution IX); TTLs and caps stay code constants
(constitution VI).

**Scale/Scope**: 8 workers per concurrency test; ~9 key families; 6 source files changed, 2 new.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | How this plan meets it |
|---|---|---|
| I. Privacy Fails Closed | Pass | A store error refuses the call (`store.unavailable`); no in-process fallback (Out of Scope). Pseudonym state that cannot be written still fails closed (`pseudonym.keyfail`). |
| II. Read-Only by Default | Pass | No tool added. The probe key is the gate's own key with a 5 s TTL. |
| III. Deny by Default | Pass | Unchanged. HTTP store failure answers before the token check, with no hint about tools. |
| IV. No Response Without a Receipt | Pass | Receipts go to the log channel and do not use the cache store (FR-008); every `store.unavailable` refusal writes one, HTTP included. |
| V. Code Decides | Pass | Store classification and fail-closed mapping are code. |
| VI. Config Narrows | Pass | No new setting. TTLs, lock wait and map caps are constants; the store name selects only where state lives. |
| VII. Failures Quiet to Model | Pass | Model sees `Request failed. Reference: …`; the note carries the class of the store error only; `gate:check` prints the class only. |
| VIII. Production Authenticated | Pass | Unchanged. FPM test uses fake tokens named `FAKE_FIXTURE_ONLY`. |
| IX. Test-First | Pass | Every implementation task follows a failing test. The Redis and FPM suites are opt-in jobs like the install matrix; `composer test` stays offline. |
| X. Minimal Dependencies | Pass | No package added; the native extension is used in CI only. |

**Result**: all gates pass. No Complexity Tracking entry.

## Project Structure

### Documentation (this feature)

```text
specs/008-redis-cache-store/
├── plan.md
├── research.md                 # R1–R8
├── data-model.md
├── quickstart.md
├── contracts/store-check-and-jobs.md
└── tasks.md
```

### Source Code (repository root)

```text
src/Support/GateCache.php            # name(), key(), lockKey(), guard()
src/Support/StoreUnavailable.php     # new exception, original as previous
src/Server/ReasonCode.php            # StoreUnavailable = 'store.unavailable' (failed)
src/Server/ResponseBuilder.php       # forReason maps it to failed(); crashed() not used for it
src/Server/GateCallTool.php          # catch StoreUnavailable before Throwable
src/Server/FirewallStep.php          # same
src/Server/FirewallRunner.php        # transform: StoreUnavailable -> ReasonCode, not keyfail
src/Server/PseudonymArguments.php    # rethrow StoreUnavailable instead of "unknown"
src/Server/Failure.php               # note() records previous class for StoreUnavailable
src/Pipeline/Budget.php              # keys via GateCache::key(); locked() inside guard()
src/Privacy/Pseudonymizer.php        # lock key via lockKey(); lock block inside guard()
src/Privacy/PseudonymStates.php      # store calls inside guard(); size refusal outside
src/Http/RefusalLedger.php           # keys via GateCache::key(); public methods inside guard()
src/Http/GateRefusalThrottle.php     # 503 + receipt on StoreUnavailable
src/Readiness/Checks/StoreCheck.php  # new; registered in src/Readiness/CheckRegistry.php

tests/Unit/Support/GateCacheTest.php           # extended: name, key, lockKey, guard
tests/Unit/Readiness/Checks/StoreCheckTest.php # new
tests/Unit/Pipeline/BudgetKeysTest.php         # new: key names and TTLs with a recording store
tests/Unit/Http/RefusalLedgerKeysTest.php      # new
tests/Unit/Privacy/PseudonymizerKeysTest.php   # new
tests/Contract/StoreUnavailableTest.php        # new: tool call, mid-call, pseudonym, recovery
tests/Contract/HttpStoreUnavailableTest.php    # new
tests/Concurrency/ConcurrencyTestCase.php      # store selection, per-run Redis prefix, sweep helper
tests/Concurrency/Workers/worker.php           # redis store, day/week/session pseudonym modes
tests/Concurrency/KeyFamilies.php              # test-only pattern -> max TTL map
tests/Concurrency/BudgetProcessTest.php        # day budget, repeat, exact
tests/Concurrency/RefusalLedgerProcessTest.php # exact on Redis, one throttle receipt
tests/Concurrency/PseudonymProcessTest.php     # batches in day, week, persistent session
tests/Concurrency/KeyIsolationTest.php         # two prefixes on one Redis (US5)
tests/Concurrency/KeyTtlSweepTest.php          # SC-004
tests/EndToEnd/FpmSessionTest.php              # opt-in wrapper for scripts/fpm-test.sh
scripts/fpm-test.sh                            # FPM + nginx session check
.github/workflows/ci.yml                       # job concurrency-redis; commented block removed
docs/concurrency.md, docs/configuration.md, CHANGELOG.md
```

**Structure Decision**: one helper (`GateCache`) owns the store name, key namespace and the error boundary, so every
writer goes through it and a reviewer can grep one class. Store classification is one readiness check. The
concurrency suite is extended in place, not duplicated per store.

## Design Decisions

- **US1 (gate:check)**: `StoreCheck` (research R1). Reads the store name through `GateCache::name()` only, so the
  spec 009 switch is one line (R7).
- **US2 (concurrency)**: worker selects the store by env; each test asserts exact outcomes (FR-006). The refusal
  ledger on Redis must count exactly: Redis `INCR` is atomic even on the unlocked fallback; on the file store the
  existing bounded assertion stays.
- **US3 (FPM sessions)**: `pm.max_requests = 1` forces a new process per request (R6). Sliding expiry is a unit test
  with a frozen clock.
- **US4 (outage)**: `GateCache::guard` + `StoreUnavailable` + one reason code (R4). The guard wraps store calls only,
  so a non-store bug keeps its existing code.
- **US5 (shared Redis)**: namespace via `GateCache::key` (R2); empty prefix WARN in `StoreCheck`; isolation test
  with two prefixes.
- **US6 (TTL)**: values already correct (research table); add the sweep and the lost-TTL test on Redis.
- **Order with spec 009**: if 009 lands first, T005 changes `GateCache::name()` to read the 009 settings path; if 008
  lands first, 009's tasks change that one line. Neither blocks.

## Complexity Tracking

No violations.
