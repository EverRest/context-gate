<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Runbook;

use ContextGate\Tests\Fixtures\Runbook\MixedRunbook;
use ContextGate\Tests\Fixtures\Runbook\UnsafeRunbook;
use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

/** spec 018 US5 AC3: gate:check prints one line per registered runbook and fails only the invalid one. */
class CheckCommandRunbookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
    }

    public function test_a_valid_runbook_passes_and_an_invalid_one_is_named(): void
    {
        config(['context-gate.runbooks' => ['mixed' => MixedRunbook::class, 'unsafe' => UnsafeRunbook::class]]);

        Artisan::call('gate:check');
        $output = Artisan::output();

        $this->assertStringContainsString('PASS runbooks.mixed', $output);
        $this->assertStringContainsString('FAIL runbooks.unsafe', $output);
        $this->assertStringNotContainsString('FAIL runbooks.mixed', $output);
    }
}
