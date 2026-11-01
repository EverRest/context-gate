<?php

declare(strict_types=1);

namespace ContextGate\Tests\EndToEnd;

use ContextGate\Tests\Fixtures\RelationProbeContract;
use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

/** T030, User Story 2 scenario 1, SC-003: a correct installation passes every check and exits 0. */
class CheckCorrectInstallTest extends TestCase
{
    public function test_a_correct_installation_passes_every_check_and_exits_0(): void
    {
        TokenFactory::migrate();
        config(['context-gate.tools' => ['probe' => ['enabled' => true, 'contract' => RelationProbeContract::class]]]);
        config(['context-gate.classification' => [
            'orders' => ['status' => 'public', 'customer_id' => 'internal'],
            'customers' => ['name' => 'public'],
        ]]);
        config(['context-gate.canaries.values' => ['FAKE_FIXTURE_ONLY_canary']]);

        $this->assertSame(0, Artisan::call('gate:check'));

        $output = Artisan::output();

        $this->assertStringNotContainsString('FAIL', $output);
        $this->assertStringNotContainsString('WARN', $output);
    }
}
