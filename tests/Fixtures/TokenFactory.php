<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fixtures;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Contracts\HasApiTokens as ApiTokensContract;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;

/**
 * Sanctum tokens for tests, in the in-memory test database. Run migrate() once per test case.
 */
final class TokenFactory
{
    /** Creates personal_access_tokens (Sanctum), gate_token_issuers and the token holder row in the test database. */
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

        // Sanctum's guard resolves the token's tokenable, so the holder must be a real row.
        Schema::create('token_holders', function (Blueprint $table): void {
            $table->id();
        });
        DB::table('token_holders')->insert(['id' => 1]);
    }

    /** @param list<string> $abilities */
    public static function create(array $abilities, ?DateTimeInterface $expiresAt = null, string $name = 'Test Holder'): NewAccessToken
    {
        return self::user()->createToken($name, $abilities, $expiresAt);
    }

    /**
     * The tokenable owner, row 1 of token_holders; migrate() must have run.
     *
     * @return Model&ApiTokensContract
     */
    public static function user(): Model
    {
        return TokenHolder::query()->findOrFail(1);
    }
}

final class TokenHolder extends Model implements ApiTokensContract
{
    use HasApiTokens;

    protected $table = 'token_holders';

    protected $guarded = [];

    public $timestamps = false;
}
