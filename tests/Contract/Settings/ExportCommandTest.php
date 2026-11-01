<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Settings;

use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

/** spec 007 T044 (US6, FR-012): gate:config:export prints no canary value and no secret-named key. */
class ExportCommandTest extends TestCase
{
    private const SENTINEL = 'CANARY_FAKE_FIXTURE_ONLY_export_44';

    public function test_export_exits_zero_and_holds_no_canary_value(): void
    {
        config(['context-gate.canaries.values' => [self::SENTINEL]]);

        $exit = Artisan::call('gate:config:export');

        $this->assertSame(0, $exit);
        $this->assertStringNotContainsString(self::SENTINEL, Artisan::output());
        $this->assertStringNotContainsString(hash('sha256', self::SENTINEL), Artisan::output());
    }

    public function test_export_holds_no_secret_named_key(): void
    {
        Artisan::call('gate:config:export');

        $this->assertNoSecretKeys(json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR));
    }

    /** @param array<array-key, mixed> $node */
    private function assertNoSecretKeys(array $node): void
    {
        foreach ($node as $name => $value) {
            if (is_string($name) && ! is_array($value)) {
                $this->assertDoesNotMatchRegularExpression('/secret|token|password|credential/i', $name);
            }

            if (is_array($value)) {
                $this->assertNoSecretKeys($value);
            }
        }
    }
}
