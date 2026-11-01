<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Console;

use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

/** T046 and US5 scenario 3: canary values have the email and token shapes, and differ between runs. */
class CanaryShapeTest extends TestCase
{
    private const EMAIL = '/canary-[0-9a-f]{8}@example\.invalid/';

    private const TOKEN = '/CANARY_[0-9a-f]{24}/';

    public function test_output_holds_an_email_canary_and_a_token_canary_marked_as_canaries(): void
    {
        Artisan::call('gate:canary');
        $output = Artisan::output();

        $this->assertMatchesRegularExpression(self::EMAIL, $output);
        $this->assertMatchesRegularExpression(self::TOKEN, $output);
        $this->assertStringContainsString('canary', strtolower($output));
    }

    public function test_values_differ_between_runs(): void
    {
        Artisan::call('gate:canary');
        $first = Artisan::output();
        Artisan::call('gate:canary');
        $second = Artisan::output();

        $this->assertNotSame($first, $second);
        preg_match(self::TOKEN, $first, $firstToken);
        preg_match(self::TOKEN, $second, $secondToken);
        $this->assertNotSame($firstToken, $secondToken);
    }
}
