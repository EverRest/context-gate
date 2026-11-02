# Feature Specification: Redis as the Tested Cache Store

**Feature Branch**: `008-redis-cache-store`

**Created**: 2026-11-01

**Status**: Clarified

**Input**: Owner request: "improvements the package needs: Redis as the main cache store with real concurrency tests;
one path to settings; caller roles instead of token ids". This spec takes the first item. Specs 009 (settings single
source) and 010 (caller roles) take the others.

## Context

The gate keeps its shared state in one cache store (`cache_store`, default: the host default store):

- the per-minute call limit per caller;
- the session and per-day byte budgets;
- the HTTP refusal counters (per IP and credential, per IP, and one summary marker per IP);
- the pseudonym state of day, week and persistent-session scopes, and the locks that guard it.

Today atomicity is proven with mocked caches, plus an uncommitted opt-in suite that runs worker processes against a
file store (`docs/concurrency.md`). No test runs against Redis, which is the store the first consumer (m-seq) uses.
`gate:check` only verifies that `cache_store` is a string. It does not say when a store cannot hold the limits across
workers. Key names use three different prefixes (`gate:`, `budget:`, `context-gate:`). Nothing states what happens
when the store is down.

This spec makes Redis the recommended and tested store. It adds no runtime dependency: the gate uses the host's cache
store through the framework, and the host chooses its Redis client.

## Clarifications

### Session 2026-11-01

- Q: Is a `database` store accepted as a shared store (PASS), or warned like `file`? (FR-002) → A: WARN in every environment. Counters and locks are atomic across hosts, but every call adds writes and row locks to the application database; allowed with a warning.
- Q: Does an eviction policy other than `noeviction` produce a WARN or a FAIL in production? (FR-012) → A: WARN in production, not FAIL. Docs recommend a dedicated Redis connection/database for the gateway with `noeviction`; a shared Redis with the app cache works, but an evicted counter temporarily widens a limit (residual risk stated).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - The operator learns that the store cannot hold the limits (Priority: P1)

An operator runs `gate:check`. When the configured store cannot do atomic increments or locks shared by every worker,
the check says so in plain text, names `cache_store` and the store driver, and says what to use instead.

**Why this priority**: A per-process store silently gives each worker its own budget. The limits look configured but
do not hold. This is the cheapest protection and needs no new test infrastructure.

**Independent Test**: Run `gate:check` with `cache_store` set to an `array` store, a `file` store, a `database` store
and a `redis` store; each gives the result in FR-002.

**Acceptance Scenarios**:

1. **Given** `cache_store` names an `array` store, **When** `gate:check` runs, **Then** it fails, names `cache_store`,
   says that the counters reset in each process, and recommends a Redis store.
2. **Given** `cache_store` names a `file` store, **When** `gate:check` runs in production, **Then** it fails; in any
   other environment it warns. The text says that a file store holds the limits only on one host.
3. **Given** `cache_store` names a `redis` store that answers, **When** `gate:check` runs, **Then** the store check
   passes.
4. **Given** `cache_store` is null and the host default store is `array`, **When** `gate:check` runs, **Then** the
   result is the same as for an explicit `array` store, and the text names the host default.
5. **Given** `cache_store` names a `database` store, **When** `gate:check` runs, **Then** it warns (not fails), names
   `cache_store`, and says that every call adds writes and row locks to the application database.

---

### User Story 2 - Limits hold under real concurrency against Redis (Priority: P1)

A maintainer runs the opt-in concurrency suite. Many worker processes hit one budget, one rate limit, one refusal
counter and one pseudonym scope at the same time against a real Redis. No limit is exceeded. No pseudonym is lost or
issued twice.

**Why this priority**: Mocked caches cannot show a lost update or an overshoot. This is the proof the first consumer
needs before it relies on the limits.

**Independent Test**: Start a Redis service, set the opt-in variable, run the concurrency group; every test passes.
Without the variable, `composer test` skips the group and opens no connection.

**Acceptance Scenarios**:

1. **Given** a session limit and eight workers each reserving bytes, **When** they run at once, **Then** the bytes
   served never exceed the limit, and the limit is reached exactly when demand exceeds it.
2. **Given** a per-minute limit of N and eight workers making 2N calls together, **When** they run at once, **Then**
   exactly N calls are admitted in the window.
3. **Given** one (IP, credential) pair and eight workers each recording refusals, **When** they run at once, **Then**
   the counter equals the number of refusals recorded, and exactly one `http.throttled` receipt is written when the
   pair reaches its limit.
4. **Given** eight workers each minting pseudonyms for overlapping record batches in one day scope, **When** they run
   at once, **Then** every worker gets the same pseudonym for the same record, the reverse map holds one entry per
   record, and every issued pseudonym resolves afterwards.
5. **Given** the opt-in variable is not set, **When** `composer test` runs, **Then** the group is skipped and no
   process or network connection is opened.

---

### User Story 3 - Session pseudonyms survive across HTTP requests (Priority: P1)

An assistant calls a tool over HTTP and gets a session pseudonym. In the next request of the same session it passes
that pseudonym back. The gate resolves it, even though each request ran in a different PHP-FPM worker.

**Why this priority**: Over HTTP every request is a new process. If the session state does not reach the shared store,
a pseudonym the model was given cannot be used in the next call. Under a process-per-request server this is the normal
case, not an edge case.

**Independent Test**: In the opt-in job, serve the gate through a real PHP-FPM pool backed by Redis; issue a
pseudonym in request 1 and resolve it in request 2 served by a different worker.

**Acceptance Scenarios**:

1. **Given** a pseudonym issued in a session in request 1, **When** request 2 of the same session passes it back,
   **Then** it resolves to the same record.
2. **Given** the same pseudonym, **When** a request of a different session or caller passes it back, **Then** it does
   not resolve, and the receipt records `pseudonym.unknown`.
3. **Given** a session idle for longer than its sliding expiry, **When** the pseudonym is passed back, **Then** it does
   not resolve.

---

### User Story 4 - The store is down: the gate refuses, never serves without counters (Priority: P1)

Redis stops answering. Every tool call is refused with a reason. No call is served without its budget and rate limit
being counted.

**Why this priority**: Fail closed is an owner decision. A gate that serves when it cannot count has no limits.

**Independent Test**: Point `cache_store` at a Redis address that refuses connections; call a tool; the call is
refused, the model sees the generic message, and the receipt records `store.unavailable`.

**Acceptance Scenarios**:

1. **Given** the store is unreachable, **When** a tool is called, **Then** the call is refused, the handler is not
   called, and the receipt records `store.unavailable`.
2. **Given** the store is unreachable, **When** an HTTP request arrives, **Then** the refusal throttle cannot count,
   and the request is refused with a generic error before the token is checked.
3. **Given** the store fails in the middle of a call (after the rate limit, before the budget), **When** the call
   continues, **Then** it is refused and no row is served.
4. **Given** the store is unreachable, **When** `gate:check` runs, **Then** it fails, names `cache_store`, and prints
   the exception class only, never the message or the connection details.
5. **Given** the store comes back, **When** the next call arrives, **Then** it is served normally, with no restart.

---

### User Story 5 - Several applications share one Redis (Priority: P2)

Two applications that both use the gate point at the same Redis. Their counters and pseudonym state never mix.

**Why this priority**: Shared Redis is common in hosted platforms. A collision would let one application spend or
reset another's budget, or resolve another's pseudonym.

**Independent Test**: Two configurations with different cache prefixes on one Redis; reserve bytes and issue a
pseudonym in one; the other sees an empty budget and cannot resolve the pseudonym.

**Acceptance Scenarios**:

1. **Given** two applications with different cache prefixes on one Redis, **When** both reserve bytes for caller
   `token:1`, **Then** each application has its own counter.
2. **Given** a cache store whose prefix is empty, **When** `gate:check` runs, **Then** it warns that keys can collide
   with another application and names the store.
3. **Given** any key the gate writes, **When** it is listed, **Then** it starts with the store prefix followed by the
   one package namespace.

---

### User Story 6 - No key lives forever (Priority: P2)

An operator inspects the gate's keys in Redis. Every key has an expiry. Memory used by the gate is bounded by the
number of callers, sessions and days in use, not by elapsed time.

**Why this priority**: A key without an expiry is a slow memory leak and, for a counter, a limit that never resets
or never clears.

**Independent Test**: After the opt-in suite, list every key under the package namespace; none has "no expiry".

**Acceptance Scenarios**:

1. **Given** the concurrency suite has run, **When** every key under the package namespace is listed, **Then** each
   key has a remaining time to live, and none is above its family's maximum in FR-010.
2. **Given** a counter key that expired between the add and the increment, **When** the increment recreates it,
   **Then** the key gets its TTL again in the same operation.

---

### Edge Cases

- The store answers but evicts keys under memory pressure: a budget counter that is evicted resets, which widens the
  limit. This is a WARN in production, not a FAIL; a dedicated Redis with `noeviction` removes the risk. See FR-012.
- A lock cannot be acquired within its wait time (a slow worker holds it): the call is refused with
  `store.unavailable`; it is never served without the lock.
- A worker dies while holding a lock: the lock expires after its own TTL and the next worker continues.
- The pseudonym state of one scope exceeds the store's value size limit: the write is refused and the call fails
  closed (existing behaviour, `pseudonym.keyfail`).
- `APP_KEY` rotates: cached pseudonym state can no longer be decrypted and is replaced; older pseudonyms stop
  resolving (existing behaviour).
- Clock skew between workers: day counters are keyed by the UTC date of the worker that writes; a call at midnight
  may count against either day. The window is the size of the skew, and the per-minute limit still applies in it.
- Redis is a cluster: keys of one counter and its lock may land on different shards. The lock and the counter are
  each atomic on their own shard; see Assumptions.
- `cache_store` names a store that does not exist in the host's cache configuration: `gate:check` fails and names
  `cache_store` (and every call is refused with `store.unavailable`).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The documentation MUST name Redis as the recommended store for `cache_store` and state the minimum the
  store must provide: atomic increments shared by every worker, locks shared by every worker, and per-key expiry.
- **FR-002**: `gate:check` MUST classify the configured store (or the host default when `cache_store` is null):
  - `array` or `null` driver: FAIL in every environment. Text: the counters reset in each process.
  - `file` driver: FAIL in production, WARN elsewhere. Text: the limits hold on one host only.
  - `database` driver: WARN in every environment. Text: counters and locks are atomic across hosts, but every call adds
    writes and row locks to the application database. The store is allowed; the warning is not a failure.
  - `redis` driver: PASS when the store answers a write, read and delete of a probe key under the package namespace.
  - `memcached` driver: PASS when it answers the same probe.
  - Any other driver: WARN, naming the driver; the gate cannot prove it is atomic.
  Each result names `cache_store`, the store name and the driver, and says what to do.
- **FR-003**: A separate opt-in concurrency suite MUST run real worker processes against a real Redis. It covers the
  byte budgets (session and day), the per-minute limit, the refusal ledger (per credential, per IP and the summary),
  and pseudonym batches in day, week and persistent-session scopes.
- **FR-004**: The concurrency suite MUST run only when its opt-in variable is set. `composer test` MUST skip it and
  open no network connection (constitution IX). It MUST run in its own CI job with a Redis service, next to the
  existing install matrix, on the Laravel versions the package supports.
- **FR-005**: The opt-in job MUST include an HTTP check under a process-per-request server (PHP-FPM): a session
  pseudonym issued in one request resolves in a later request of the same session served by another worker, and
  does not resolve for a different session or caller.
- **FR-006**: Each concurrency test MUST assert an exact outcome (no overshoot, exact admitted count, exact counter
  value, one map entry per record), not only "no error".
- **FR-007**: When the store fails (connection refused, timeout, lock not acquired in time, write refused), the call
  MUST be refused with a new reason code `store.unavailable`, the handler MUST NOT run, and no row MUST be served.
  The model receives the generic error message and the receipt identifier (constitution VII).
- **FR-008**: The receipt for a store failure MUST still be written; receipts do not depend on the cache store. The
  operator note holds the receipt identifier, the exception class, the file basename and the line, and never the
  exception message or connection details (constitution VII).
- **FR-009**: Every key the gate writes MUST start with one package namespace (`context-gate:`), after the store's
  own prefix. The current `gate:` and `budget:` prefixes are replaced. The rate limiter's keys are included.
- **FR-010**: Every key the gate writes MUST be written with an expiry. The maximum per key family:

  | Key family | Maximum time to live |
  |---|---|
  | Per-minute rate limit | 60 seconds (one window) |
  | Session byte budget | the session TTL |
  | Day byte budget | until the next 00:00 UTC (at most 86400 seconds) |
  | Refusal counters and the refusal summary marker | 60 seconds |
  | Day pseudonym state | 86400 seconds |
  | Week pseudonym state | 604800 seconds |
  | Persistent-session pseudonym state | sliding 3600 seconds, never past creation plus 604800 seconds |
  | Locks | 5 seconds |

  No code path may write a gate key without an expiry, including the path that recreates a key that vanished between
  add and increment.
- **FR-011**: Memory per key family MUST stay bounded. The existing caps apply and are documented: at most 10000
  records per pseudonym scope map (the key rotates beyond it), at most 100 session scopes held in process memory, and
  one counter per caller per window or day. The number of keys is bounded by callers, sessions and days in use within
  the TTLs above.
- **FR-012**: `gate:check` MUST report the Redis eviction policy when it can read it. A policy that may evict the
  gate's keys (any policy other than `noeviction`) MUST produce a WARN in production (never a FAIL), and no result
  elsewhere beyond the reported policy. The documentation MUST recommend a dedicated Redis connection or database for
  the gateway with `noeviction`, and MUST state the residual risk: a Redis shared with the application cache works,
  but an evicted counter temporarily widens a limit until the key is written again. When the policy cannot be read (a managed Redis that hides it), the check WARNs and says so.
- **FR-013**: `gate:check` MUST WARN when the configured store's prefix is empty, because keys may collide with
  another application on the same server.
- **FR-014**: The gate MUST NOT add a runtime dependency for Redis. It uses the host's cache store through the
  framework. A Redis client library MAY be added as a development dependency for the opt-in suite only, when the CI
  image has no native client; the plan states the justification.
- **FR-015**: `docs/concurrency.md` and `docs/configuration.md` MUST describe the Redis job, the opt-in variable, the
  key namespace, the TTL table and the `gate:check` store results.

### Key Entities

- **Gate store**: the cache store named by `cache_store`, or the host default; holds every counter, pseudonym state
  and lock.
- **Key family**: a group of keys with one purpose and one maximum TTL (FR-010).
- **Store check result**: PASS, WARN or FAIL from `gate:check`, with the store name, driver and advice.
- **Concurrency run**: one opt-in test that starts N worker processes against one key family and asserts an exact
  outcome.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: In the opt-in suite against Redis, the bytes served never exceed a session or day limit, in 100 out of
  100 repeated runs with eight workers.
- **SC-002**: In the same suite, exactly N calls are admitted for a per-minute limit of N, in every run.
- **SC-003**: In the same suite, no pseudonym is issued twice for different records and none is lost (0 cases).
- **SC-004**: After the suite, 0 keys under the package namespace have no expiry.
- **SC-005**: With the store unreachable, 0 tool calls are served, and 100% of them have a receipt with
  `store.unavailable`.
- **SC-006**: `composer test` opens 0 network connections and starts 0 worker processes.
- **SC-007**: For each store driver in FR-002, `gate:check` gives the stated result (100% of cases in its tests).

## Out of Scope

- **Serving a call when the store is down, with in-process counters as a fallback** (owner decision fail closed;
  principle I in spirit: a failure must remove data, not widen access). Per-process counters do not hold the limits.
- **A Redis-specific runtime client or a direct Redis connection owned by the package** (principle X, Minimal
  Dependencies). The framework cache store is enough.
- **A Lua script or other store-specific atomic path in runtime code** (principle X): the framework's increment and
  lock are used; if Redis proves them insufficient, a later spec decides.
- **Running the concurrency suite in `composer test`** (principle IX: no network in the default suite).
- **Configuring TTLs or map caps** (principle VI: limits are in code; configuration may only lower them, and no owner
  need to lower these was stated).
- **Monitoring or metrics export for the store**: no consumer asked for it; receipts already record refusals.

## Assumptions

- The first consumer (m-seq) runs Redis and MySQL; MongoDB is being dropped and is not a store target.
- "Multi-process workers" means separate PHP processes on one host in CI; multi-host behaviour follows from Redis
  being one shared server and is not tested separately.
- The HTTP check uses a PHP-FPM pool in the CI job; it needs no browser and no external network beyond the local
  Redis service.
- A Redis cluster is supported only as far as the framework's cache store supports it. A counter and its lock are each
  atomic, which is what the budget needs; cross-key transactions are not used.
- The store's own prefix (the host's cache prefix) is the application namespace. The package adds no prefix setting.
- The concurrency suite already started for the file store (`tests/Concurrency`, uncommitted) is the base this spec
  extends; the file-store runs stay as a single-host check.
- Lock wait (3 seconds) and lock TTL (5 seconds) stay as they are today.
- A Redis shared with the application cache is accepted with a WARN. The residual risk (an evicted counter widens a
  limit until it is written again) is documented, not prevented; the recommendation is a dedicated connection or
  database with `noeviction`.
- A `database` cache store is accepted with a WARN: it is correct across hosts but loads the application database.
- The day-boundary skew edge case is accepted, because the per-minute limit bounds it.

## Amendments (owner decisions, 2026-11-01)

- 2026-11-01: when the cursor store is unavailable, the query tool REFUSES the call (fail closed); it does not return a page without a cursor.
- 2026-11-01: the cache key rename resets budget counters once at upgrade. Accepted.
- 2026-11-01 (owner, ceiling): the refusal counter can be created without a TTL when its key expires between add and increment; that one (IP, credential) pair then stays throttled until the key is cleared. A re-put that would set the TTL overwrites concurrent increments on Redis (154 of 160 counted), so it is not used. Accepted residual, recorded as a `ponytail:` ceiling in `src/Http/RefusalLedger.php`. Upgrade path: an atomic TTL-setting primitive on the store. The parked test `tests/Unit/Http/RefusalLedgerExpiryTest.php` is a skipped known gap, not a failing test.
- 2026-11-02 (owner, CI): the nightly 100-repeat run of the `concurrency-redis` job is non-blocking (`continue-on-error` on the schedule event) until `BudgetProcessTest` passes 100/100 on Redis. It failed with a lock timeout in the PHPUnit harness; it is not reproduced by a direct worker loop, so the cause is open. The push run with `CONTEXT_GATE_REPEAT=5` passes and stays blocking.
- 2026-11-02 (owner, CI): the nightly schedule runs the `concurrency-redis` job only; every other job is skipped on the schedule event.
- 2026-11-02 (owner, FPM): the PHP-FPM session check (FR-005, T037/T038) is NOT implemented and NOT VERIFIED on this machine: nginx is absent, php-fpm 8.3 is present. T037 and T038 stay open.
- 2026-11-02 (reviewer, corrects the 2026-11-01 ceiling line): the TTL gap applies to Redis and the file store alike: when a refusal counter's key expires between add and increment, the counter is created without a TTL and never expires. The effect is per (IP, credential) for the counter itself, and for the per-IP counter `refusals:ip:<ip>` it is worse: a stuck IP key silences the `http.throttled` receipts of every anonymous refusal from that IP, for good. Accepted residual, unchanged decision: no re-put.
- 2026-11-02 (reviewer, D8 upgrade effect, supersedes the 2026-11-01 "resets budget counters" note): the key rename resets the per-minute limiter (`context-gate:rate:`), the refusal counters and trip markers as well as the budgets. A throttled client is free once after the upgrade. The pseudonym key is NOT renamed (`context-gate:pseudonym:<scope>` is unchanged), so persistent pseudonyms survive the upgrade. During a rolling deploy a pseudonym issued by an old worker can be lost, because an old worker's state write can overwrite the new worker's state for the same scope.
- 2026-11-02 (CI, Laravel leg): the `concurrency-redis` job runs Laravel 12 only. The Redis behaviour does not depend on the Laravel major, and the main test jobs already cover Laravel 13 (test, PHP 8.5, highest deps). The Laravel 13 leg of that job did not resolve with Testbench 11 and was removed.
- 2026-11-02 (CI, repeats): push keeps `CONTEXT_GATE_REPEAT=5`; it passes locally on the file store and on Redis with the job's variables (after the worker fix below). Nightly stays at 100 and non-blocking until `BudgetProcessTest` passes 100/100.
- 2026-11-02 (CI, worker): the concurrency workers did not register the package provider, so after the settings single source (spec 009) every worker died resolving `ActiveSettings`. The worker now registers `ContextGateServiceProvider` as a host does; the failing step was the file-store run in CI. The default `composer test` does not run these workers, so only the opt-in suite catches this kind of break.
