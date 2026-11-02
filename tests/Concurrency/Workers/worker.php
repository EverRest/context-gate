<?php

/**
 * One worker process for the concurrency tests. Boots only the cache, encryption and config services, all on a file
 * store at the path given as argv[1]. Prints one JSON line on stdout. Usage:
 *
 *   php worker.php <store-dir> <start-at-unix-seconds> <mode> [args...]
 */

declare(strict_types=1);

namespace ContextGate\Tests\Concurrency\Workers;

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
use Illuminate\Support\Facades\Facade;

require dirname(__DIR__, 3).'/vendor/autoload.php';

[, $storeDir, $startAt, $mode] = $_SERVER['argv'];
$args = array_slice($_SERVER['argv'], 4);

// Same key in every worker: a state written by one process must decrypt in another. Test-only key.
$key = 'base64:'.base64_encode(hash('sha256', 'context-gate-concurrency', true));

$app = new Application(sys_get_temp_dir());
$app->instance('config', new ConfigRepository([
    'app' => ['env' => 'testing', 'key' => $key, 'cipher' => 'AES-256-CBC'],
    'cache' => ['default' => 'file', 'stores' => ['file' => ['driver' => 'file', 'path' => $storeDir]]],
]));
$app->instance('files', new Filesystem());
$app->register(CacheServiceProvider::class);
$app->register(EncryptionServiceProvider::class);
Facade::setFacadeApplication($app);
Pseudonymizer::usePersistentSessions(true, 3600);

// Start all workers together: sleep until the shared start time.
$wait = (float) $startAt - microtime(true);

if ($wait > 0) {
    usleep((int) ($wait * 1_000_000));
}

$result = match ($mode) {
    'budget-session' => budgetSession((int) $args[0], (int) $args[1]),
    'budget-calls' => budgetCalls((int) $args[0], (int) $args[1]),
    'refusals' => refusals((int) $args[0]),
    'refusals-count' => ['count' => (int) GateCache::store()->get(RefusalLedger::key(refusalRequest()))],
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
function budgetSession(int $limit, int $attempts): array
{
    $budget = new Budget(
        GateCache::store(),
        new RateLimiter(GateCache::store()),
        'alice',
        'shared',
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
function budgetCalls(int $perMinute, int $attempts): array
{
    $budget = new Budget(GateCache::store(), new RateLimiter(GateCache::store()), 'alice', 'shared', 3600, perMinute: $perMinute);
    $admitted = 0;

    for ($i = 0; $i < $attempts; $i++) {
        if ($budget->admit() === null) {
            $admitted++;
        }
    }

    return ['admitted' => $admitted];
}

/** Records refusals from one (IP, credential) pair, as a flood from one client does. Nothing is returned.
 *
 * @return array<never> */
function refusals(int $attempts): array
{
    // Above any count this test reaches, so the throttle never trips and only the count is under test.
    global $app;
    $app['config']->set('context-gate.http.refusals_per_minute', 500);
    $request = refusalRequest();

    for ($i = 0; $i < $attempts; $i++) {
        RefusalLedger::record($request);
    }

    return [];
}

function refusalRequest(): Request
{
    return Request::create('/', 'POST', server: ['REMOTE_ADDR' => '198.51.100.9', 'HTTP_AUTHORIZATION' => 'Bearer 1|shared']);
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

/** $scope is the stored scope, for example day:alice:tool. */
/** @return array<string, int> */
function pseudonymInspect(string $scope): array
{
    $state = PseudonymStates::read(GateCache::key('pseudonym:'.$scope), time());

    return ['entries' => count($state['map'] ?? [])];
}
