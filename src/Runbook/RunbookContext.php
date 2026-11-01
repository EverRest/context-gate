<?php

declare(strict_types=1);

namespace ContextGate\Runbook;

use Illuminate\Support\Env;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The only service a runbook step receives. Seven read checks; each returns a bool or an int
 * and never a stored value. The checks are reads: no job is dispatched and no cache key is written.
 *
 * A reachability check returns false when the connection fails. A migration count does not hide a failure: a database
 * error propagates, so the runner reports the step as error. A missing failed-jobs table has no failures, so it counts 0.
 *
 * @internal May change without notice.
 */
final class RunbookContext
{
    public function envPresent(string $name): bool
    {
        return Env::get($name) !== null;
    }

    public function configPresent(string $key): bool
    {
        try {
            return config()->has($key);
        } catch (Throwable) {
            return false;
        }
    }

    public function cacheReachable(string $store): bool
    {
        try {
            Cache::store($store)->get('context-gate:runbook:reachable');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function queueReachable(string $connection): bool
    {
        try {
            app('queue')->connection($connection)->size();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function migrationsPending(): int
    {
        $migrator = app('migrator');
        $files = $migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()));
        $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];

        return count(array_diff(array_keys($files), $ran));
    }

    public function failedJobCount(): int
    {
        $table = (string) config('queue.failed.table', 'failed_jobs');

        return Schema::hasTable($table) ? (int) DB::table($table)->count() : 0;
    }

    public function routeExists(string $nameOrUri): bool
    {
        $routes = app('router')->getRoutes();

        if ($routes->getByName($nameOrUri) !== null) {
            return true;
        }

        foreach ($routes->getRoutes() as $route) {
            if (ltrim($route->uri(), '/') === ltrim($nameOrUri, '/')) {
                return true;
            }
        }

        return false;
    }
}
