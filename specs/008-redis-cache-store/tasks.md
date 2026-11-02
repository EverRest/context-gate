# Tasks: Redis as the Tested Cache Store

**Input**: Design documents from `specs/008-redis-cache-store/`

**Prerequisites**: plan.md, spec.md, research.md (R1–R8), data-model.md, contracts/store-check-and-jobs.md. Specs
001–007 complete. Spec 009 is not required (research R7).

**Tests**: REQUIRED (constitution IX). Each implementation task follows a test task that must fail first. Tasks marked
*verification* lock behaviour that already exists at HEAD; their test is expected to pass when written. No test in
`composer test` may open a socket or start a process.

**Organization**: by user story. US1, US2, US3, US4 are P1; US5 and US6 are P2.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: US1…US6 from spec.md
- Paths are relative to the repository root

---

## Phase 1: Setup

- [x] T001 Write test in `tests/Unit/Support/GateCacheTest.php`: `GateCache::name()` returns `cache_store` when it is a string and null otherwise; `GateCache::key('budget:day:x')` is `context-gate:budget:day:x`; `GateCache::lockKey('rate:x')` is `context-gate:lock:rate:x`
- [x] T002 Edit `src/Support/GateCache.php` to add `name()`, `key()`, `lockKey()`; `store()` uses `name()`

---

## Phase 2: Foundational

**Purpose**: the error boundary and the reason code every story uses.

**Checkpoint**: `guard()` and `store.unavailable` are green; no call site uses them yet.

- [x] T003 [P] Write test in `tests/Unit/Support/GateCacheTest.php`: `GateCache::guard(fn () => throw new \RuntimeException('host=10.0.0.1 password=FAKE_FIXTURE_ONLY'))` throws `StoreUnavailable` whose message contains neither string and whose `getPrevious()` is the original; a returning closure passes its value through; `LockTimeoutException` is wrapped too
- [x] T004 Create `src/Support/StoreUnavailable.php` and add `GateCache::guard(Closure $fn): mixed`
- [x] T005 [P] Write test `tests/Unit/Server/ReasonCodeStoreTest.php`: `ReasonCode::from('store.unavailable')->decision()` is `failed`; `ResponseBuilder::forReason(..., ReasonCode::StoreUnavailable, 'r_x')` gives `Request failed. Reference: r_x` with `isError` true
- [x] T006 Edit `src/Server/ReasonCode.php` (case and decision) and `src/Server/ResponseBuilder.php` (`forReason` failed arm)
- [x] T007 [P] Write test `tests/Unit/Server/FailureStoreNoteTest.php`: `Failure::note(new StoreUnavailable(previous: new \RedisException('tcp://10.0.0.1')), 'r_x')` writes `exception` = `RedisException` (stub class in the test if the extension is absent), the file basename and line of the original, and no message text
- [x] T008 Edit `src/Server/Failure.php` so a `StoreUnavailable` is noted with its previous exception's class, file and line

---

## Phase 3: User Story 1 - The operator learns that the store cannot hold the limits (Priority: P1)

**Goal**: `gate:check` classifies the store per FR-002, FR-012, FR-013.

**Independent Test**: `gate:check` with array, file, database and redis stores gives the results of data-model.md.

- [x] T009 [P] [US1] Write test `tests/Unit/Readiness/Checks/StoreCheckTest.php` with mocked `Cache` and config: array and null drivers FAIL in every env with "reset in each process"; file FAILs in production and WARNs in local with "one host"; database WARNs with "row locks"; redis with a probe that round-trips PASSes; redis whose probe throws FAILs naming only the class (message with a fake host absent); an unknown driver WARNs naming it; a store name missing from `cache.stores` FAILs; null `cache_store` with default `array` FAILs and says `(host default)`; every result's subject is `context-gate.cache_store` and names store and driver
- [x] T010 [P] [US1] Write test in the same file for modifiers: Redis policy `allkeys-lru` WARNs in production and passes elsewhere with the policy in the text; `CONFIG` throwing WARNs "could not be read"; empty prefix WARNs; probe key matches `context-gate:probe:` and is written with TTL 5
- [x] T011 [US1] Create `src/Readiness/Checks/StoreCheck.php` (research R1) and register it in `src/Readiness/CheckRegistry.php`
- [x] T012 [US1] Write test `tests/Contract/Settings/CheckStoreTest.php` (wiring): `gate:check` with `cache.default` = `array` exits 1 and prints `context-gate.cache_store`; with a `file` store in `local` exits 0 with a WARN line (expected green after T011)

**Checkpoint**: US1 works with no Redis.

---

## Phase 4: User Story 4 - The store is down: the gate refuses (Priority: P1)

**Goal**: every store error refuses with `store.unavailable`; no row served; recovery without restart.

**Independent Test**: a store whose calls throw; a tool call is refused with `store.unavailable`.

- [x] T013 [P] [US4] Write test `tests/Unit/Pipeline/BudgetGuardTest.php`: a cache whose `increment` throws makes `admit()` and `handle()` throw `StoreUnavailable`; a lock that times out (`block` throws `LockTimeoutException`) does too; the session reservation is released when the day reservation fails (existing behaviour kept)
- [x] T014 [US4] Edit `src/Pipeline/Budget.php`: `locked()` runs inside `GateCache::guard()`
- [x] T015 [P] [US4] Write test `tests/Unit/Privacy/PseudonymStoreGuardTest.php`: a store whose `get` throws makes `resolve()` throw `StoreUnavailable`; whose `put` throws makes `pseudonyms()` throw `StoreUnavailable`; whose `put` returns false still throws the size `RuntimeException` (not `StoreUnavailable`)
- [x] T016 [US4] Edit `src/Privacy/PseudonymStates.php` and `src/Privacy/Pseudonymizer.php` to wrap store and lock calls in `GateCache::guard()`, keeping the false-`put` refusal outside
- [x] T017 [P] [US4] Write test `tests/Contract/StoreUnavailableTest.php` (with `tests/Contract/GateCallHarness.php` and a store that throws): a call is refused with `Request failed. Reference: r_…`, the handler count is 0, the receipt has `store.unavailable`, the note has the store exception class and no message; failing after `admit()` and before the budget (US4 #3) serves no row and records `store.unavailable`; a pseudonym transform with the store down records `store.unavailable`, not `pseudonym.keyfail`; a pseudonym argument with the store down records `store.unavailable`, not `pseudonym.unknown`; after the store is restored the next call is served (US4 #5)
- [x] T018 [US4] Edit `src/Server/GateCallTool.php`, `src/Server/FirewallStep.php`, `src/Server/FirewallRunner.php`, `src/Server/PseudonymArguments.php` to map `StoreUnavailable` to `ReasonCode::StoreUnavailable` before their generic `Throwable` handling
- [x] T019 [P] [US4] Write test `tests/Contract/HttpStoreUnavailableTest.php`: with the store throwing, a POST to the HTTP path answers 503 with the generic JSON-RPC error, `GateTokenAuth` is not reached (spy), one `store.unavailable` receipt with caller `unknown` is written and contains no IP; a refusal whose `record()` fails still returns its 401
- [x] T020 [US4] Edit `src/Http/RefusalLedger.php` (public methods inside `guard()`) and `src/Http/GateRefusalThrottle.php` (503 and receipt)
- [x] T021 [US4] *verification* Write test `tests/Contract/Settings/CheckStoreDownTest.php`: `gate:check` with a Redis store pointed at a closed port is not run (no socket in `composer test`); instead a store whose `put` throws makes `gate:check` exit 1 naming `context-gate.cache_store` and the class only (US4 #4; logic from T011)

**Checkpoint**: SC-005 holds with a throwing store in the default suite.

---

## Phase 5: User Story 5 - Several applications share one Redis (Priority: P2)

**Goal**: one namespace `context-gate:` after the store prefix.

**Independent Test**: unit tests list every key written; all start with `context-gate:`.

- [x] T022 [P] [US5] Write test `tests/Unit/Pipeline/BudgetKeysTest.php` with a recording array store: `admit()` writes `context-gate:rate:<caller>` (and `:timer`); `handle()` writes `context-gate:budget:session:<s>` and `context-gate:budget:day:<caller>:<date>`; locks are `context-gate:lock:…`
- [x] T023 [P] [US5] Write test `tests/Unit/Http/RefusalLedgerKeysTest.php`: keys `context-gate:refusals:cred:`, `:ip:`, `:summary:`, `:tripped:`; no key starts with `gate:`
- [x] T024 [P] [US5] Write test `tests/Unit/Privacy/PseudonymizerKeysTest.php`: state key `context-gate:pseudonym:<scope>`, lock key `context-gate:lock:pseudonym:<scope>`
- [x] T025 [US5] Edit `src/Pipeline/Budget.php`, `src/Http/RefusalLedger.php`, `src/Privacy/Pseudonymizer.php`, `tests/Concurrency/Workers/worker.php` (`pseudonym-inspect`, `refusals-count`) to build every key with `GateCache::key()` / `lockKey()` (research R2)
- [x] T026 [US5] Grep check task: `grep -rn "'gate:\|'budget:" src` returns only `src/Token/Ability.php` (abilities, not keys). Record the result in the PR description; no test

---

## Phase 6: User Story 2 - Limits hold under real concurrency against Redis (Priority: P1)

**Goal**: the opt-in suite runs on Redis with exact outcomes.

**Independent Test**: `CONTEXT_GATE_CONCURRENCY=1 CONTEXT_GATE_STORE=redis vendor/bin/phpunit --testsuite Concurrency`.

- [x] T027 [US2] Edit `tests/Concurrency/ConcurrencyTestCase.php`: `store()` from `CONTEXT_GATE_STORE`; a per-run Redis prefix `cg-<hex>:` passed to workers; `redisKeys()` helper (phpredis `SCAN` under the prefix); `tearDown` deletes the prefix's keys; skip when the variable is set to `redis` and the `redis` extension is missing
- [x] T028 [US2] Edit `tests/Concurrency/Workers/worker.php`: register `RedisServiceProvider` and a `redis` cache store (phpredis, `REDIS_HOST`, `REDIS_PORT`, database 15, the run prefix) when the store is `redis`; add modes `budget-day` and `pseudonym-batch <scope> <caller> <first> <count>`
- [x] T029 [P] [US2] Write test in `tests/Concurrency/BudgetProcessTest.php`: day budget, eight workers, bytes served ≤ limit and limit filled exactly; all budget and rate tests loop `CONTEXT_GATE_REPEAT` times (FR-006, SC-001, SC-002)
- [x] T030 [P] [US2] Write test in `tests/Concurrency/RefusalLedgerProcessTest.php`: on Redis the settled count equals workers × attempts exactly, and with a limit inside the range exactly one `http.throttled` receipt line is written across workers (workers log to a shared file channel); on file the existing bounded assertion stays
- [x] T031 [P] [US2] Write test in `tests/Concurrency/PseudonymProcessTest.php`: overlapping batches in day, week and persistent-session scopes; every worker gets the same pseudonym per record; the map holds one entry per record; every issued pseudonym resolves in a fresh process (SC-003)
- [x] T032 [US2] Run the suite on file and on a local Redis (quickstart steps 2–3); fix any failure in `src/` with a failing unit test first
- [x] T033 [P] [US2] Write test `tests/Unit/Concurrency/OptInGateTest.php`: with `CONTEXT_GATE_CONCURRENCY` unset, every `ConcurrencyTestCase` subclass is skipped in `setUp` before any `proc_open` or socket (spy through a subclass) (FR-004, SC-006)

---

## Phase 7: User Story 6 - No key lives forever (Priority: P2)

**Goal**: every key has a TTL within its family maximum.

**Independent Test**: after the Redis suite, 0 keys without expiry (SC-004).

- [x] T034 [P] [US6] Create `tests/Concurrency/KeyFamilies.php` (test-only map, research R3) and write test `tests/Concurrency/KeyTtlSweepTest.php` *verification*: runs one worker per mode, then for every key under the run prefix `TTL > 0` and `TTL ≤` the family maximum; an unmatched key fails the test
- [x] T035 [P] [US6] Write test in `tests/Concurrency/KeyTtlSweepTest.php` *verification*: delete a budget key between `add` and `increment` (worker mode `budget-vanish`) and assert the recreated key has a TTL (US6 #2)
- [x] T036 [P] [US6] Write test `tests/Unit/Privacy/PseudonymSlidingExpiryTest.php` *verification* with a frozen clock: a persistent session state slides to now + 3600 on use, never past created + 604800, and does not resolve after an idle hour (US3 #3)

---

## Phase 8: User Story 3 - Session pseudonyms survive across HTTP requests (Priority: P1)

**Goal**: a session pseudonym resolves in a later FPM request served by another worker.

**Independent Test**: `CONTEXT_GATE_FPM_TEST=1 vendor/bin/phpunit tests/EndToEnd/FpmSessionTest.php`.

- [ ] T037 [US3] Write test `tests/EndToEnd/FpmSessionTest.php`: skipped unless `CONTEXT_GATE_FPM_TEST=1`; runs `scripts/fpm-test.sh` and asserts exit 0 and the three result lines (resolves, different worker, other caller `pseudonym.unknown`)
- [ ] T038 [US3] Create `scripts/fpm-test.sh` (research R6): skeleton install (reuse `scripts/install-test.sh` functions), Redis store, two fake tokens, php-fpm `pm.max_requests = 1`, nginx, three requests, receipt line check
- [ ] T039 [US3] Write test `tests/Unit/Server/SessionScopeTest.php` *verification*: `FirewallRunner::scopeKey` for a session field is `session:<caller>`, so a different caller's scope differs (US3 #2)

---

## Phase 9: CI

- [ ] T040 Edit `.github/workflows/ci.yml`: remove the commented `services: redis` block from `test`; add job `concurrency-redis` per contracts/store-check-and-jobs.md (Laravel 12 and 13, Redis 7.4 service with `noeviction`, file run, Redis run, FPM run, `CONTEXT_GATE_REPEAT` 5 on push and 100 on a nightly `schedule`)
- [ ] T041 Write test in `tests/Contract/ReleaseWorkflowTest.php` (existing workflow assertions): `ci.yml` has a `concurrency-redis` job with a `redis` service and `CONTEXT_GATE_CONCURRENCY: '1'`, and the `test` job does not set `CONTEXT_GATE_CONCURRENCY`

---

## Phase 10: Polish and Cross-Cutting

- [ ] T042 [P] Update `docs/concurrency.md`: Redis job, variables, FPM check, key namespace, TTL table, map caps (FR-011, FR-015), cluster and skew notes
- [ ] T043 [P] Update `docs/configuration.md` `cache_store`: Redis recommended, minimum store guarantees (FR-001), `gate:check` results per driver, dedicated connection with `noeviction`, eviction residual risk (FR-012), empty prefix warning, outage = `store.unavailable`
- [ ] T044 [P] Update `CHANGELOG.md` (Unreleased): new reason code `store.unavailable`, new store check, key rename (budgets restart once on upgrade, research R2)
- [ ] T045 [P] Update `README.md` requirements line: Redis recommended for `cache_store`
- [ ] T046 If spec 009 is merged: edit `GateCache::name()` to read `cache_store` through the 009 settings path, with its test in `tests/Unit/Support/GateCacheTest.php` first (research R7). Otherwise leave a line in spec 009's tasks
- [ ] T047 Run `composer style`, `composer stan`, `composer length`, `composer test`; read each exit code; all must be 0
- [ ] T048 Run quickstart.md steps 2–7 against a local Redis and record the result

---

## Dependencies and Execution Order

- Phase 1 → Phase 2 → stories. US1 uses no Phase 2 class; it can start after T002.
- US4 (Phase 4) before US2 (Phase 6): the worker must see `StoreUnavailable` paths stable before the suite runs.
- US5 (Phase 5) before US6 (Phase 7): the sweep matches the new key names.
- US3 (Phase 8) needs US5 (key names) and Phase 9's job to run in CI; locally it runs on its own.
- [P] tasks touch different files. Each test task fails before its implementation task (except *verification*).
