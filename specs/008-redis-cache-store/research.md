# Research: Redis as the Tested Cache Store

Code read at HEAD `d744441` (after `b59bd05`). Paths are relative to the repository root.

## State found in the code

| Item | Where | State |
|---|---|---|
| Store selection | `src/Support/GateCache.php` | `config('context-gate.cache_store')` or the host default. One entry point for every caller. |
| Rate limit | `src/Pipeline/Budget.php::admit` | `RateLimiter::hit('gate:'.$caller, 60)` under a `context-gate:budget:lock:` lock. Laravel's `RateLimiter` re-puts a key that lost its TTL (`! $added && $hits == 1`). |
| Byte budgets | `Budget::reserve/release` | `budget:session:<session>` (TTL = session TTL 3600), `budget:day:<caller>:<Y-m-d>` (TTL to UTC midnight). Lost-TTL guard present. Lock 5 s, wait 3 s. |
| Refusal ledger | `src/Http/RefusalLedger.php` | `gate:refusals:`, `gate:refusals-ip:`, `gate:refusals-summary:`, `gate:refusals-tripped:` (all 60 s). Credential counter under `context-gate:refusals:lock:` with `block(0)` and an unlocked fallback. |
| Pseudonym state | `src/Privacy/Pseudonymizer.php`, `PseudonymStates.php` | `context-gate:pseudonym:<scope>`; day 86400, week 604800, persistent session sliding 3600 capped at created + 604800; lock `context-gate:pseudonym:lock:<scope>` 5 s / 3 s; `MAX_MAP` 10000, `MAX_MEMORY_SESSIONS` 100. |
| HTTP persistent sessions | `src/Server/GateServer.php::boot` | `Pseudonymizer::usePersistentSessions(true)` on HTTP. Session scope is `session:{caller}` (`FirewallRunner::scopeKey`). |
| Store failure today | `GateCallTool::handle`, `FirewallStep::run`, `FirewallRunner::transform`, `PseudonymArguments::record` | Any exception becomes `handler.failed`, `pseudonym.keyfail` or `pseudonym.unknown`. No `store.unavailable`. |
| gate:check | `src/Settings/SettingsSchema.php` | `cache_store` typed `string?` only. No store check in `src/Readiness/CheckRegistry.php`. |
| Concurrency suite | `tests/Concurrency/` (committed, not "uncommitted" as the spec says) | File store only: `BudgetProcessTest` (session, per-minute), `PseudonymProcessTest`, `RefusalLedgerProcessTest` (bounded, may undercount on file), `ConfigLockProcessTest`. Gate: `CONTEXT_GATE_CONCURRENCY=1`. Own `Concurrency` testsuite in `phpunit.xml.dist`. |
| CI | `.github/workflows/ci.yml` | A commented `services: redis` block in the `test` job; `redis` extension already installed by `setup-php`. `install-test` job runs `scripts/install-test.sh` per Laravel major. |

Already satisfied, verification only: TTL on every key family listed in FR-010 (values match the table), lost-TTL
re-put on budget, refusal and rate-limit counters, lock TTL 5 s and wait 3 s, map caps of FR-011.

## R1. How `gate:check` classifies the store (FR-002, FR-012, FR-013)

**Decision**: one new check, `src/Readiness/Checks/StoreCheck.php`, returning the worst status over three findings
in one `CheckResult` (the registry runs one result per check).

1. Resolve the store name: `cache_store`, else `config('cache.default')`. A name missing from `cache.stores` is FAIL.
2. Driver from `config("cache.stores.{$name}.driver")`:
   `array`, `null` → FAIL; `file` → FAIL in production, WARN elsewhere; `database` → WARN; `redis`, `memcached` →
   probe; anything else → WARN naming the driver.
3. Probe (redis, memcached only): `put` / `get` / `forget` of `context-gate:probe:<16 hex>` with TTL 5 s. Any
   exception or a mismatch → FAIL with the exception class only (`explode("\0", $e::class)[0]`, the sanitiser used by
   `Failure::note` and `RunbookValidator`). Never the message (it can carry host, port or password).
4. Redis only, eviction: `$store->getStore()->connection()->command('config', ['get', 'maxmemory-policy'])`. Not
   `noeviction` → WARN in production, PASS-with-text elsewhere; unreadable (managed Redis blocks `CONFIG`) → WARN
   "policy could not be read".
5. Prefix: `$store->getStore()->getPrefix() === ''` → WARN (FR-013).

**Why not probe increment and lock per driver**: the framework implements them per driver; the driver name already
tells which are shared (FR-002 lists results by driver, not by probe). A probe increment on Redis cannot reveal a
non-shared store. The probe only proves the store answers.

**Alternatives rejected**: three separate checks (three lines for one setting, harder to read); probing with a lock
(adds a lock key per `gate:check` run, proves nothing more than the driver name).

## R2. Key namespace (FR-009)

**Decision**: one helper, `GateCache::key(string $suffix): string` returning `context-gate:`.$suffix, and
`GateCache::lockKey(string $suffix)` returning `context-gate:lock:`.$suffix. Every key is built through them.

| Family | Today | New |
|---|---|---|
| Rate limit (and Laravel's `:timer`) | `gate:<caller>` | `context-gate:rate:<caller>` |
| Session budget | `budget:session:<session>` | `context-gate:budget:session:<session>` |
| Day budget | `budget:day:<caller>:<date>` | `context-gate:budget:day:<caller>:<date>` |
| Refusals per credential | `gate:refusals:<ip>:<hash>` | `context-gate:refusals:cred:<ip>:<hash>` |
| Refusals per IP | `gate:refusals-ip:<ip>` | `context-gate:refusals:ip:<ip>` |
| Refusal summary marker | `gate:refusals-summary:<ip>` | `context-gate:refusals:summary:<ip>` |
| Refusal trip marker | `gate:refusals-tripped:<key>` | `context-gate:refusals:tripped:<ip>:<hash>` |
| Pseudonym state | `context-gate:pseudonym:<scope>` | unchanged |
| Locks | `context-gate:{budget,pseudonym,refusals}:lock:<key>` | `context-gate:lock:<suffix of the guarded key>` |
| gate:check probe | none | `context-gate:probe:<hex>` (TTL 5 s) |

**Migration**: none. The package has no users before 010 (spec 009 assumption). Old keys expire on their own TTL
(at most one day for `budget:day:`). Effect on an upgrade: counters restart once at deploy, which can grant up to
one extra day budget per caller on that day. Release note only.

**Alternatives rejected**: a configurable prefix (spec Assumption: the store prefix is the application namespace);
a key class with one method per family (one helper is enough; the family is the suffix).

## R3. TTL per family (FR-010)

| Family | Maximum | Source of the value |
|---|---|---|
| `rate:` (+ `:timer`) | 60 s | `RateLimiter::hit($key, 60)` |
| `budget:session:` | 3600 s | `GateCallTool::SESSION_TTL` |
| `budget:day:` | ≤ 86400 s | `Budget::secondsUntilUtcMidnight` |
| `refusals:*` | 60 s | `RefusalLedger::WINDOW` |
| `pseudonym:day:` | 86400 s | `Pseudonymizer::TTL['day']` |
| `pseudonym:week:` | 604800 s | `Pseudonymizer::TTL['week']` |
| `pseudonym:session:` | 3600 s sliding, ≤ created + 604800 | `usePersistentSessions`, `MAX_LIFETIME` |
| `lock:` | 5 s | lock constructor argument |
| `probe:` | 5 s | `StoreCheck` |

A test-only map `tests/Concurrency/KeyFamilies.php` holds this table (pattern → max TTL) for the sweep (SC-004). It
is not runtime code: TTLs stay constants in their owning classes (spec Out of Scope: no TTL configuration).

## R4. Store outage → `store.unavailable` (FR-007, FR-008)

**Decision**:

- New `ReasonCode::StoreUnavailable = 'store.unavailable'`, decision `failed`; `ResponseBuilder::forReason` maps it to
  `failed()` (generic text plus reference).
- New exception `src/Support/StoreUnavailable.php` (`final class … extends RuntimeException`, carries the original as
  `previous`, message fixed: "The gate store is unavailable.").
- `GateCache::guard(Closure $fn)`: runs `$fn`, rethrows any `Throwable` as `StoreUnavailable`. Wrapped sites: the body
  of `Budget::locked` (covers `admit`, `reserve`, `release`, `LockTimeoutException`), `Pseudonymizer::pseudonyms`
  lock block and `PseudonymStates::read/write` store calls, `RefusalLedger` public methods. The size-limit refusal in
  `PseudonymStates::write` (`put` returns false) stays `pseudonym.keyfail` (spec edge case) and is thrown outside the
  guard.
- Mapping to the receipt: `GateCallTool::handle` and `FirewallStep::run` catch `StoreUnavailable` before
  `Throwable`; `FirewallRunner::transform` returns `ReasonCode::StoreUnavailable` for it instead of
  `pseudonym.keyfail`; `PseudonymArguments::record` rethrows it instead of answering "unknown". The note uses the
  previous exception's class (FR-008: the class of what failed, not of the wrapper).
- Receipts do not touch the cache store (`Receipt::write` writes to the log channel), so FR-008 holds as is.
- HTTP (US4 #2): `GateRefusalThrottle::handle` catches `StoreUnavailable` from `blocked()` and answers 503 with a
  generic JSON-RPC error (`-32000`, "Service unavailable."), writes a `store.unavailable` receipt for caller
  `unknown`, before any token work. A failure in `record()` after the response is noted and the refusal response is
  returned unchanged (the request was already refused).
- Recovery (US4 #5): no state is cached about the outage; the next call hits the store again.

**Alternatives rejected**: a decorating `Repository` that wraps every store call (larger, and it would also wrap the
size refusal); catching driver-specific classes (`RedisException`, Predis) — the host picks the client, the package
cannot list them.

**Spec mismatch to flag**: FR-007 lists "write refused" as a store failure, the Edge Cases keep an over-size
pseudonym write as `pseudonym.keyfail`. This plan follows the edge case for `put() === false` on pseudonym state and
treats only exceptions and lock timeouts as `store.unavailable`.

## R5. The opt-in Redis job (FR-003, FR-004, FR-014)

**Decision**:

- The worker (`tests/Concurrency/Workers/worker.php`) chooses the store from `CONTEXT_GATE_STORE` (`file` default,
  `redis`). For `redis` it registers `Illuminate\Redis\RedisServiceProvider` with `client = phpredis`, host from
  `REDIS_HOST`/`REDIS_PORT`, a per-run cache prefix (`cg-<hex>:`) so runs never share keys, and `database` 15.
- `ConcurrencyTestCase` gains `store()` returning the selected driver and, for Redis, flushes the per-run prefix in
  `tearDown` after the TTL sweep.
- Gate variables: `CONTEXT_GATE_CONCURRENCY=1` (existing) runs the suite; `CONTEXT_GATE_STORE=redis` selects Redis.
  Without the first, every test is skipped before any process or socket (SC-006). The default suite keeps both unset.
- Client: the native `phpredis` extension, already installed in CI by `setup-php`. No Composer dependency (FR-014).
- CI: a new `concurrency-redis` job in `.github/workflows/ci.yml`, matrix Laravel 12 and 13 (Testbench `^10` / `^11`
  via `composer require --dev orchestra/testbench:<v> --with-all-dependencies`), PHP 8.3, `services: redis:7.4` with a
  health check, `maxmemory-policy noeviction`. The commented block in the `test` job is removed. It runs
  `vendor/bin/phpunit --testsuite Concurrency` for both stores (file then redis).
- The 100-run criterion (SC-001) is a `REPEAT` env var read by the budget tests (default 1, CI 100 on a nightly
  `schedule:` trigger; 5 on push to keep the job short).

**Alternatives rejected**: Predis as a dev dependency (not needed while the extension exists; FR-014 allows it only
if the image lacks a native client); Testcontainers (network pull, slower, Docker in Docker).

## R6. PHP-FPM check (FR-005)

**Decision**: `scripts/fpm-test.sh`, run by the `concurrency-redis` job after the PHPUnit run.

1. Build a fresh skeleton with the package, as `scripts/install-test.sh` does (reuse its install function).
2. Configure `CACHE_STORE=redis`, `cache_store=redis`, an HTTP path, and issue two test tokens with `gate:token`
   (values stay in the script's temp dir; fake names `FAKE_FIXTURE_ONLY`).
3. Start `php-fpm` with `pm = static`, `pm.max_children = 2`, `pm.max_requests = 1` (each worker exits after one
   request, so request 2 is necessarily served by another process), and `nginx` with a FastCGI pass to it. Both are on
   the Ubuntu runner (`php8.3-fpm` from the PPA `setup-php` configures; `nginx` preinstalled).
4. Request 1 calls a fixture tool returning a session pseudonym; request 2 passes it back with the same token: it
   resolves. Request 3 with the second token: it does not resolve and the receipt line records `pseudonym.unknown`.
5. Sliding expiry (US3 #3) is covered by a unit test with a frozen clock, not in FPM (an hour-long wait is not a CI
   test).

A PHPUnit wrapper `tests/EndToEnd/FpmSessionTest.php` runs the script only with `CONTEXT_GATE_FPM_TEST=1`, matching
the install-test pattern (constitution IX).

**Alternatives rejected**: `php -S` with `PHP_CLI_SERVER_WORKERS` (not PHP-FPM, which FR-005 names); asserting
worker PIDs through a test route (needs a route in the host; `max_requests = 1` proves the process change without
one).

## R7. Settings path order (spec 009)

**Decision**: `GateCache::name(): ?string` becomes the only reader of `cache_store`. Today it reads
`config('context-gate.cache_store')`. When spec 009 lands first, the one line changes to the settings accessor
(`EffectiveSettings` or its 009 successor); `StoreCheck` reads the name through `GateCache::name()`, never config.
Either order works: the task that touches the line is the last task of whichever spec lands second.

## R8. Cluster, skew, eviction (accepted risks)

No code. Documented in `docs/concurrency.md` and `docs/configuration.md`: a counter and its lock are each atomic;
day keys use the writer's UTC date; an evicted counter widens a limit until rewritten (WARN only).
