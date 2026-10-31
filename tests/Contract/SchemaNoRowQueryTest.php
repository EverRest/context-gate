<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

require_once __DIR__ . '/../Fixtures/app/app/Models/User.php';

use App\Models\User;
use ContextGate\Classification\Catalog;
use ContextGate\Source\SchemaSource;
use ContextGate\Source\SourceRequest;
use ContextGate\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class SchemaNoRowQueryTest extends TestCase
{
    public function test_describing_a_model_runs_no_select_on_its_table(): void
    {
        $this->createTables();
        DB::table('users')->insert(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'FAKE_FIXTURE_ONLY']);

        $catalog = new Catalog(['users' => ['id' => 'public', 'name' => 'personal', 'email' => 'personal']]);
        DB::enableQueryLog();

        (new SchemaSource($catalog, [User::class]))->read(new SourceRequest(['model' => User::class]));

        $selects = [];
        $writes = [];
        $others = [];

        foreach (DB::getQueryLog() as $query) {
            // A row read selects FROM the model's table; schema reflection reads pragma functions and catalogs.
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', (string) $query['query']) === 1) {
                $writes[] = $query['query'];
            }

            if (preg_match('/\bfrom\s+["`]?orders["`]?(\s|$)/i', (string) $query['query']) === 1) {
                $others[] = $query['query'];
            }

            if (preg_match('/^\s*select\b.*\bfrom\s+["`]?users["`]?(\s|$)/is', (string) $query['query']) === 1) {
                $selects[] = $query['query'];
            }
        }

        $this->assertSame([], $selects);
        $this->assertSame([], $writes);
        $this->assertSame([], $others);
    }

    private function createTables(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->integer('total');
        });
    }
}
