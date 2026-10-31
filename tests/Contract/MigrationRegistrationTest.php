<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/** Rule 3: a fresh application that runs migrate gets the gate's tables from the package, with no manual step. */
class MigrationRegistrationTest extends TestCase
{
    public function test_fresh_app_migrate_creates_the_gate_tables(): void
    {
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));

        $this->assertTrue(Schema::hasTable('gate_token_holders'));
        $this->assertTrue(Schema::hasTable('gate_access_tokens'));
        $this->assertTrue(Schema::hasTable('gate_token_issuers'));
    }
}
