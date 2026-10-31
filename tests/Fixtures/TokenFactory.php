<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fixtures;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Contracts\HasApiTokens as ApiTokensContract;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;

/**
 * Sanctum tokens for tests, in the in-memory test database. Run migrate() once per test case.
 */
final class TokenFactory
{
    /** Creates personal_access_tokens (Sanctum) and gate_token_issuers in the test database. */
    public static function migrate(): void
    {
        $root = dirname(__DIR__, 2);

        // The fixture sqlite config leaves foreign keys off; production Laravel turns them on by default.
        DB::statement('PRAGMA foreign_keys = ON');

        foreach (array_merge(
            glob($root.'/vendor/laravel/sanctum/database/migrations/*.php') ?: [],
            glob($root.'/database/migrations/*.php') ?: [],
        ) as $file) {
            $migration = require $file;
            assert(is_object($migration) && method_exists($migration, 'up'));
            $migration->up();
        }
    }

    /** @param list<string> $abilities */
    public static function create(array $abilities, ?DateTimeInterface $expiresAt = null, string $name = 'Test Holder'): NewAccessToken
    {
        return self::user()->createToken($name, $abilities, $expiresAt);
    }

    /**
     * A tokenable owner; no users table is needed because the token only stores its id.
     *
     * @return Model&ApiTokensContract
     */
    public static function user(): Model
    {
        $user = new class () extends Model implements ApiTokensContract {
            use HasApiTokens;

            protected $guarded = [];

            public function getMorphClass(): string
            {
                return 'test-user';
            }
        };

        $user->forceFill(['id' => 1]);

        return $user;
    }
}
