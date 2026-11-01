<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Console;

use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

/** A preview does not run in production; it says so instead of letting the stdio server exit with a misleading message. */
class PreviewProductionTest extends TestCase
{
    public function test_preview_refuses_in_production_with_a_clear_message(): void
    {
        $app = $this->app;

        if ($app === null) {
            $this->fail('The application is not booted.');
        }

        $app->detectEnvironment(static fn (): string => 'production');

        $status = Artisan::call('gate:preview', ['tool' => 'probe', '--args' => '{}']);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('gate:preview does not run in production', Artisan::output());
        $this->assertStringNotContainsString('stdio', Artisan::output());
    }
}
