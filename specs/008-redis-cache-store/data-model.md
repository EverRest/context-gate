# Data Model: Redis as the Tested Cache Store

No database table. Entities are cache keys, one reason code and one check result.

## Gate store

| Attribute | Value |
|---|---|
| Name | `GateCache::name()`: `cache_store`, else `cache.default` |
| Driver | `cache.stores.<name>.driver` |
| Prefix | `Store::getPrefix()` (the host's cache prefix, the application namespace) |

## Key families

Every key: `<store prefix>context-gate:<family>:<parts>` (research R2). Every write carries a TTL (research R3).

| Family | Key pattern after `context-gate:` | Value | Max TTL | Writer |
|---|---|---|---|---|
| rate | `rate:<caller>`, `rate:<caller>:timer` | int hits, int reset time | 60 s | `Budget::admit` via `RateLimiter` |
| budget session | `budget:session:<session>` | int bytes | 3600 s | `Budget::reserve/release` |
| budget day | `budget:day:<caller>:<YYYY-MM-DD>` | int bytes | to next 00:00 UTC | `Budget::reserve/release` |
| refusals cred | `refusals:cred:<ip>:<hash16>` | int | 60 s | `RefusalLedger::bump` |
| refusals ip | `refusals:ip:<ip>` | int | 60 s | `RefusalLedger::record` |
| refusals summary | `refusals:summary:<ip>` | 1 | 60 s | `RefusalLedger::summary` |
| refusals tripped | `refusals:tripped:<ip>:<hash16>` | 1 | 60 s | `RefusalLedger::record` |
| pseudonym | `pseudonym:{day,week}:<caller>:<tool>`, `pseudonym:session:<caller>` | encrypted JSON state (≤ 10000 map entries) | 86400 / 604800 / sliding 3600 ≤ created + 604800 | `PseudonymStates::write` |
| lock | `lock:<suffix of guarded key>` | lock owner | 5 s | `Budget::locked`, `Pseudonymizer::pseudonyms`, `RefusalLedger::bump` |
| probe | `probe:<hex16>` | `1` | 5 s | `StoreCheck` |

Bounds (FR-011): one rate and one session key per caller, one day key per caller per day, refusal keys per active
(IP, credential) within 60 s, at most 100 session states in process memory (stdio), 10000 records per scope map.

## Reason code

| Code | Decision | Response | Receipt note |
|---|---|---|---|
| `store.unavailable` | `failed` | `Request failed. Reference: <id>` | reference, exception class of the store error, file basename, line |

## Store check result

`CheckResult` (existing) with subject `context-gate.cache_store`, status = worst of driver, probe, eviction, prefix.

| Driver | Production | Elsewhere |
|---|---|---|
| `array`, `null` | FAIL | FAIL |
| `file` | FAIL | WARN |
| `database` | WARN | WARN |
| `redis`, `memcached` (probe ok) | PASS | PASS |
| `redis`, `memcached` (probe error) | FAIL (class only) | FAIL |
| other | WARN | WARN |
| store name not in `cache.stores` | FAIL | FAIL |

Modifiers: Redis `maxmemory-policy` ≠ `noeviction` → WARN in production only; unreadable policy → WARN; empty prefix
→ WARN.

## Concurrency run

| Attribute | Value |
|---|---|
| Store | `CONTEXT_GATE_STORE` = `file` (default) or `redis` |
| Workers | 8 processes, one shared start time |
| Outcome | exact (no overshoot, exact count, one map entry per record) |
| Key isolation | per-run cache prefix `cg-<hex>:` on Redis; temp dir on file |
