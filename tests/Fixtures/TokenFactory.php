<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fixtures;

use ContextGate\Token\GateTokenHolder;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Contracts\HasApiTokens as ApiTokensContract;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;

/**
 * Gate tokens for tests, in the in-memory test database. Run migrate() once per test case.
 */
final class TokenFactory
{
    /** Runs Sanctum's and the package's migrations, a host users table and one holder row (id 1). */
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

        // The host application's users table: the gate must never issue a token to it.
        Schema::create('host_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        DB::table('gate_token_holders')->insert(['id' => 1, 'name' => 'Test Holder']);
    }

    /** @param list<string> $abilities */
    public static function create(array $abilities, ?DateTimeInterface $expiresAt = null, string $name = 'Test Holder'): NewAccessToken
    {
        return self::user()->createToken($name, $abilities, $expiresAt);
    }

    /**
     * The gate token holder, row 1 of gate_token_holders; migrate() must have run.
     */
    public static function user(): GateTokenHolder
    {
        return GateTokenHolder::query()->findOrFail(1);
    }
}

final class HostUser extends Model implements ApiTokensContract
{
    use HasApiTokens;

    protected $table = 'host_users';

    protected $guarded = [];

    public $timestamps = false;
}
