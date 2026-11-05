<?php

declare(strict_types=1);

namespace ContextGate\Readiness\Checks;

use ContextGate\Readiness\Check;
use ContextGate\Readiness\CheckResult;
use ContextGate\Readiness\CheckStatus;
use ContextGate\Readiness\ReadinessContext;
use ContextGate\Support\GateCache;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\Repository;
use Throwable;
use ContextGate\Settings\EnvironmentProfile;

/**
 * The gate store must hold counters and locks shared by every worker. The driver says whether it does; a probe says
 * whether the store answers; Redis also reports its eviction policy and the prefix is checked for collisions.
 *
 * @internal May change without notice.
 */
final class StoreCheck implements Check
{
    private const SUBJECT = 'context-gate.cache_store';

    public function name(): string
    {
        return self::class;
    }

    public function run(ReadinessContext $ctx): CheckResult
    {
        $name = GateCache::name();
        $resolved = $name ?? config('cache.default');
        $driver = is_string($resolved) ? config("cache.stores.{$resolved}.driver") : null;

        if (! is_string($driver)) {
            return CheckResult::fail(self::SUBJECT, 'store ['.$resolved.'] is not defined in cache.stores', $this->fixHint());
        }

        $label = 'store ['.$resolved.']'.($name === null ? ' (host default)' : '').' (driver '.$driver.')';

        return $this->combine(match ($driver) {
            'array', 'null' => [CheckResult::fail(self::SUBJECT, $label.': the counters reset in each process', $this->fixHint())],
            'file' => [EnvironmentProfile::appForced()
                ? CheckResult::fail(self::SUBJECT, $label.': the limits hold on one host only', $this->fixHint())
                : CheckResult::warn(self::SUBJECT, $label.': the limits hold on one host only', $this->fixHint())],
            'database' => [CheckResult::warn(self::SUBJECT, $label.': counters and locks are atomic across hosts, but every call adds writes and row locks to the application database', 'point cache_store at a dedicated Redis connection')],
            'redis', 'memcached' => $this->probe($label, $driver),
            default => [CheckResult::warn(self::SUBJECT, $label.': the gate cannot prove this driver is atomic across workers', $this->fixHint())],
        });
    }

    public function fixHint(): string
    {
        return 'set cache_store to a Redis store (a dedicated connection with maxmemory-policy noeviction) shared by every worker';
    }

    /** @return list<CheckResult> */
    private function probe(string $label, string $driver): array
    {
        try {
            $repository = GateCache::store();
            $key = GateCache::key('probe:'.bin2hex(random_bytes(8)));
            $repository->put($key, 1, 5);
            $answered = $repository->get($key) === 1;
            $repository->forget($key);
        } catch (Throwable $e) {
            // The class only: a message can carry the host, the port or the password.
            return [CheckResult::fail(self::SUBJECT, $label.' did not answer the probe ('.explode("\0", $e::class)[0].')', 'check that the store is reachable from this host')];
        }

        if (! $answered) {
            return [CheckResult::fail(self::SUBJECT, $label.' did not return the probe value', 'check that the store is reachable from this host')];
        }

        $findings = [];
        if ($repository->getStore()->getPrefix() === '') {
            $findings[] = CheckResult::warn(self::SUBJECT, $label.': the prefix is empty, so keys can collide with another application on this server', 'set a cache prefix for this application');
        }

        if ($driver === 'redis') {
            $findings[] = $this->eviction($label, $repository);
        }

        return [CheckResult::pass(self::SUBJECT, $label.' answered the probe'), ...$findings];
    }

    private function eviction(string $label, Repository $repository): CheckResult
    {
        $policy = $this->policy($repository);

        if ($policy === null) {
            return CheckResult::warn(self::SUBJECT, $label.': the eviction policy could not be read', 'use a dedicated Redis connection with maxmemory-policy noeviction');
        }

        if ($policy === 'noeviction') {
            return CheckResult::pass(self::SUBJECT, 'eviction policy noeviction');
        }

        $message = $label.': eviction policy '.$policy.' may evict the gate\'s counters, which widens a limit until they are written again';

        return EnvironmentProfile::appForced()
            ? CheckResult::warn(self::SUBJECT, $message, 'use a dedicated Redis connection with maxmemory-policy noeviction')
            : CheckResult::pass(self::SUBJECT, 'eviction policy '.$policy);
    }

    private function policy(Repository $repository): ?string
    {
        try {
            $store = $repository->getStore();
            $reply = $store instanceof RedisStore
                ? $store->connection()->command('config', ['get', 'maxmemory-policy'])
                : null;
        } catch (Throwable) {
            return null;
        }

        // phpredis answers a keyed array; Predis answers a flat pair.
        $policy = is_array($reply)
            ? ($reply['maxmemory-policy'] ?? (($reply[0] ?? null) === 'maxmemory-policy' ? ($reply[1] ?? null) : null))
            : null;

        return is_string($policy) ? $policy : null;
    }

    /** @param list<CheckResult> $findings */
    private function combine(array $findings): CheckResult
    {
        $rank = ['pass' => 0, 'warn' => 1, 'fail' => 2];
        $worst = $findings[0];
        foreach ($findings as $finding) {
            if ($rank[$finding->status->value] > $rank[$worst->status->value]) {
                $worst = $finding;
            }
        }

        $message = implode('; ', array_map(static fn (CheckResult $f): string => $f->message, $findings));
        $fix = implode('; ', array_filter(array_map(static fn (CheckResult $f): string => $f->fix, $findings)));

        return match ($worst->status) {
            CheckStatus::Fail => CheckResult::fail(self::SUBJECT, $message, $fix),
            CheckStatus::Warn => CheckResult::warn(self::SUBJECT, $message, $fix),
            CheckStatus::Pass => CheckResult::pass(self::SUBJECT, $message),
        };
    }
}
