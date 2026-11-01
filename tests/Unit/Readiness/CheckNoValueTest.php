<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Readiness;

use ContextGate\Tests\Fixtures\RelationProbeContract;
use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

/** T031, FR-014: seeded configuration values and canaries never appear in any check output. */
class CheckNoValueTest extends TestCase
{
    public function test_seeded_values_and_canaries_never_appear_in_the_output(): void
    {
        TokenFactory::migrate();
        config(['context-gate.tools' => ['probe' => ['enabled' => true, 'contract' => RelationProbeContract::class]]]);
        config(['context-gate.classification' => [
            'orders' => ['status' => 'public', 'customer_id' => 'internal'],
            'customers' => ['name' => 'public'],
        ]]);
        // Each seeded value is a failure, so its check runs its failing branch.
        config(['context-gate.canaries.values' => ['', 'FAKE_FIXTURE_ONLY_CANARY_7f3a']]);
        config(['context-gate.http.origins' => ['https://SEEDED-ORIGIN.example.invalid/FAKE_FIXTURE_ONLY_PATH']]);
        config(['context-gate.sources.openapi.allowlist' => ['https://SEEDED-HOST.example.invalid']]);

        $this->assertSame(1, Artisan::call('gate:check'));

        $output = Artisan::output();

        $this->assertStringContainsString('FAIL', $output);
        foreach (['FAKE_FIXTURE_ONLY', 'SEEDED-ORIGIN', 'SEEDED-HOST'] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
    }
}
