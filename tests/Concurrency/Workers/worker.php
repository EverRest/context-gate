<?php

/**
 * One worker process for the concurrency tests. Boots only the cache, encryption and config services, all on a file
 * store at the path given as argv[1]. Prints one JSON line on stdout. Usage:
 *
 *   php worker.php <store-dir> <start-at-unix-seconds> <mode> [args...]
 */

declare(strict_types=1);

namespace ContextGate\Tests\Concurrency\Workers;

use ContextGate\ContextGateServiceProvider;
use ContextGate\Pipeline\Budget;
use ContextGate\Http\RefusalLedger;
use ContextGate\Pipeline\Payload;
use ContextGate\Privacy\Pseudonymizer;
use ContextGate\Privacy\PseudonymStates;
use ContextGate\Support\GateCache;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Cache\RateLimiter;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Encryption\EncryptionServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Cache\Repository;
use Illuminate\Log\LogServiceProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Redis\RedisServiceProvider;
use Illuminate\Support\Facades\Facade;

require dirname(__DIR__, 3).'/vendor/autoload.php';
require __DIR__.'/VanishingRedisStore.php';

[, $storeDir, $startAt, $mode] = $_SERVER['argv'];
$args = array_slice($_SERVER['argv'], 4);

// Same key in every worker: a state written by one process must decrypt in another. Test-only key.
$key = 'base64:'.base64_encode(hash('sha256', 'context-gate-concurrency', true));

// CONTEXT_GATE_STORE=redis runs the same workers against Redis, in this run's own key prefix (CG_RUN_PREFIX) and
// database 15. Receipts go to a file next to the store, so a test can count the throttle receipts across workers.
$redis = getenv('CONTEXT_GATE_STORE') === 'redis';
$cache = $redis
    ? ['default' => 'redis', 'stores' => ['redis' => ['driver' => 'redis', 'connection' => 'default', 'prefix' => (string) getenv('CG_RUN_PREFIX')]]]
    : ['default' => 'file', 'stores' => ['file' => ['driver' => 'file', 'path' => $storeDir]]];

$app = new Application(sys_get_temp_dir());
$app->instance('config', new ConfigRepository([
    'app' => ['env' => 'testing', 'key' => $key, 'cipher' => 'AES-256-CBC'],
    'cache' => $cache,
    'database' => ['redis' => ['client' => 'phpredis', 'default' => [
        'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('REDIS_PORT') ?: 6379),
        'database' => 15,
    ]]],
    'logging' => ['default' => 'context-gate', 'channels' => ['context-gate' => [
        'driver' => 'single',
        'path' => dirname($storeDir).'/receipts.log',
        'level' => 'debug',
    ]]],
]));
$app->instance('files', new Filesystem());
$app->register(CacheServiceProvider::class);
$app->register(EncryptionServiceProvider::class);
$app->register(LogServiceProvider::class);
// The gate resolves its settings through the package provider, as a host does; without it Budget cannot be built.
$app->register(ContextGateServiceProvider::class);

if ($redis) {
    $app->register(RedisServiceProvider::class);
}
Facade::setFacadeApplication($app);
Pseudonymizer::usePersistentSessions(true, 3600);

// Start all workers together: sleep until the shared start time.
$wait = (float) $startAt - microtime(true);

if ($wait > 0) {
    usleep((int) ($wait * 1_000_000));
}

$result = match ($mode) {
    'budget-session' => budgetSession((int) $args[0], (int) $args[1], $args[2] ?? 'shared'),
    'budget-calls' => budgetCalls((int) $args[0], (int) $args[1], $args[2] ?? 'alice'),
    'refusals' => refusals((int) $args[0], (int) ($args[1] ?? 500), $args[2] ?? 'shared'),
    'refusals-count' => ['count' => (int) GateCache::store()->get(RefusalLedger::key(refusalRequest($args[0] ?? 'shared')))],
    'budget-vanish' => budgetVanish($args[0]),
    'refusals-vanish' => refusalsVanish($args[0]),
    'budget-day' => budgetDay((int) $args[0], (int) $args[1], $args[2] ?? 'alice'),
    'pseudonym-batch' => pseudonymBatch($args[0], $args[1], (int) $args[2], (int) $args[3]),
    'pseudonym-resolve-all' => pseudonymResolveAll($args[0], $args[1], explode(',', $args[2])),
    'pseudonym-mint' => pseudonymMint($args[0], $args[1], $args[2], (int) $args[3]),
    'pseudonym-resolve' => pseudonymResolve($args[0], $args[1], $args[2]),
    'pseudonym-inspect' => pseudonymInspect($args[0]),
    default => throw new \InvalidArgumentException("Unknown mode [{$mode}]."),
};

echo json_encode($result, JSON_THROW_ON_ERROR), "\n";

/**
 * Tries to serve one row of 100 encoded bytes per attempt; counts only the rows actually served.
 *
 * @return array<string, int>
 */
function budgetSession(int $limit, int $attempts, string $session): array
{
    $budget = new Budget(
        GateCache::store(),
        new RateLimiter(GateCache::store()),
        'alice',
        $session,
        3600,
        perCall: 16384,
        perSession: $limit,
        perDay: 20971520,
        perMinute: 300
    );
    $served = 0;
    $bytes = 0;

    for ($i = 0; $i < $attempts; $i++) {
        $payload = $budget->handle(new Payload([['v' => str_repeat('a', 90)]]), static fn (Payload $p): Payload => $p);

        if ($payload->rows !== []) {
            $served++;
            $bytes += $payload->bytes;
        }
    }

    return ['served' => $served, 'bytes' => $bytes];
}

/** @return array<string, int> */
function budgetCalls(int $perMinute, int $attempts, string $caller): array
{
    $budget = new Budget(GateCache::store(), new RateLimiter(GateCache::store()), $caller, 'shared', 3600, perMinute: $perMinute);
    $admitted = 0;

    for ($i = 0; $i < $attempts; $i++) {
        if ($budget->admit() === null) {
            $admitted++;
        }
    }

    return ['admitted' => $admitted];
}

/**
 * One reservation whose day counter already exists and then vanishes before its increment (spec 008 US6 #2).
 *
 * @return array<string, int>
 */
function budgetVanish(string $caller): array
{
    global $app;
    $prefix = (string) getenv('CG_RUN_PREFIX');
    $app['config']->set('cache.stores.vanish', ['driver' => 'vanish']);
    $app['config']->set('cache.default', 'vanish');
    Cache::extend('vanish', fn () => new Repository(new VanishingRedisStore($app['redis'], $prefix, 'default')));

    $day = GateCache::key('budget:day:'.$caller.':'.gmdate('Y-m-d'));
    GateCache::store()->put($day, 0, 1000);

    $budget = new Budget(GateCache::store(), new RateLimiter(GateCache::store()), $caller, 'vanish-session', 3600, perCall: 16384, perSession: PHP_INT_MAX, perDay: 20971520, perMinute: 100000);
    $payload = $budget->handle(new Payload([['v' => str_repeat('a', 90)]]), static fn (Payload $p): Payload => $p);

    return ['served' => $payload->rows === [] ? 0 : 1];
}

/**
 * Tries to serve one row per attempt against a day limit only: the session budget is never the bound.
 *
 * @return array<string, int>
 */
function budgetDay(int $limit, int $attempts, string $caller): array
{
    $budget = new Budget(
        GateCache::store(),
        new RateLimiter(GateCache::store()),
        $caller,
        'shared-day-'.bin2hex(random_bytes(4)),
        3600,
        perCall: 16384,
        perSession: PHP_INT_MAX,
        perDay: $limit,
        perMinute: 100000
    );
    $served = 0;
    $bytes = 0;

    for ($i = 0; $i < $attempts; $i++) {
        $payload = $budget->handle(new Payload([['v' => str_repeat('a', 90)]]), static fn (Payload $p): Payload => $p);

        if ($payload->rows !== []) {
            $served++;
            $bytes += $payload->bytes;
        }
    }

    return ['served' => $served, 'bytes' => $bytes];
}

/**
 * Records refusals from one (IP, credential) pair, as a flood from one client does. Nothing is returned.
 *
 * @return array<never>
 */
function refusals(int $attempts, int $limit, string $bearer): array
{
    // The limit is set by the test: above the attempts, the throttle never trips; inside them, it trips once.
    global $app;
    $app['config']->set('context-gate.http.refusals_per_minute', $limit);
    $request = refusalRequest($bearer);

    for ($i = 0; $i < $attempts; $i++) {
        RefusalLedger::record($request);
    }

    return [];
}

/**
 * One refusal whose credential counter vanishes between add and increment, after an earlier refusal created it.
 *
 * @return array<string, int>
 */
function refusalsVanish(string $bearer): array
{
    global $app;
    $prefix = (string) getenv('CG_RUN_PREFIX');
    $store = new VanishingRedisStore($app['redis'], $prefix, 'default');
    $store->needle = 'refusals:cred:';
    $app['config']->set('cache.stores.vanish', ['driver' => 'vanish']);
    $app['config']->set('cache.default', 'vanish');
    Cache::extend('vanish', fn () => new Repository($store));
    $app['config']->set('context-gate.http.refusals_per_minute', 500);

    $request = refusalRequest($bearer);
    // The counter exists, so add() does not create it: the expiry lands between add and increment.
    GateCache::store()->put(RefusalLedger::key($request), 3, 60);
    RefusalLedger::record($request);

    return [];
}

function refusalRequest(string $bearer): Request
{
    return Request::create('/', 'POST', server: ['REMOTE_ADDR' => '198.51.100.9', 'HTTP_AUTHORIZATION' => 'Bearer 1|'.$bearer]);
}

/** '-' means no caller (session scopes). */
/** @return array<string, list<string>> */
function pseudonymMint(string $scope, string $caller, string $id, int $count): array
{
    $pseudonymizer = new Pseudonymizer();
    $callerArg = $caller === '-' ? null : $caller;
    $issued = [];

    for ($i = 0; $i < $count; $i++) {
        $issued[] = $pseudonymizer->pseudonym('cus', 'customers', $id, $scope, $callerArg);
    }

    return ['pseudonyms' => $issued];
}

/** @return array<string, array{table: string, id: string}|null> */
function pseudonymResolve(string $scope, string $caller, string $pseudonym): array
{
    return ['record' => (new Pseudonymizer())->resolve($pseudonym, $scope, $caller === '-' ? null : $caller)];
}

/**
 * Mints pseudonyms for the records first..first+count-1, with ids that overlap across workers.
 *
 * @return array<string, array<string, string>> id => pseudonym
 */
function pseudonymBatch(string $scope, string $caller, int $first, int $count): array
{
    $ids = [];

    for ($i = $first; $i < $first + $count; $i++) {
        $ids[] = 'r'.$i;
    }

    $callerArg = $caller === '-' ? null : $caller;

    return ['pseudonyms' => (new Pseudonymizer())->pseudonyms('cus', 'customers', $ids, $scope, $callerArg)];
}

/**
 * Resolves each pseudonym in a fresh process; the answer per pseudonym is the record it names, or null.
 *
 * @param list<string> $pseudonyms
 * @return array{records: array<string, array{table: string, id: string}|null>}
 */
function pseudonymResolveAll(string $scope, string $caller, array $pseudonyms): array
{
    $pseudonymizer = new Pseudonymizer();
    $records = [];

    foreach ($pseudonyms as $pseudonym) {
        $records[$pseudonym] = $pseudonymizer->resolve($pseudonym, $scope, $caller === '-' ? null : $caller);
    }

    return ['records' => $records];
}

/** $scope is the stored scope, for example day:alice:tool. */
/** @return array<string, int> */
function pseudonymInspect(string $scope): array
{
    $state = PseudonymStates::read(GateCache::key('pseudonym:'.$scope), time());

    return ['entries' => count($state['map'] ?? [])];
}
