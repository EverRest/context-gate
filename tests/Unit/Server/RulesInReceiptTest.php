<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Server;

use ContextGate\ContextGateServiceProvider;
use ContextGate\Server\Receipt;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Log;

class RulesInReceiptTest extends TestCase
{
    public function test_receipt_carries_three_rule_hashes_of_six_characters(): void
    {
        /** @var array<string, string> $rules */
        $rules = app('context-gate.rules');

        $lines = $this->capture(function () use ($rules): void {
            Receipt::write('alice', 'echo', null, rules: $rules);
        });

        $record = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($record['rules']);
        $this->assertSame(['tool', 'contract', 'classification'], array_keys($record['rules']));

        foreach (['tool', 'contract', 'classification'] as $key) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{6}$/', $record['rules'][$key], $key);
        }
    }

    public function test_projection_fields_appear_only_when_given(): void
    {
        $lines = $this->capture(function (): void {
            Receipt::write('alice', 'echo', null, dropped: [['field' => 'email', 'reason' => 'field.unclassified']], truncated: true, flags: ['injection.phrase']);
        });

        $record = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([['field' => 'email', 'reason' => 'field.unclassified']], $record['dropped']);
        $this->assertTrue($record['truncated']);
        $this->assertSame(['injection.phrase'], $record['flags']);
    }

    public function test_boot_binds_the_rule_hashes_the_provider_computes(): void
    {
        $this->assertSame(ContextGateServiceProvider::ruleHashes(), app('context-gate.rules'));
    }

    /** @return list<string> */
    private function capture(callable $write): array
    {
        $lines = [];
        Log::shouldReceive('channel')->once()->with('context-gate')->andReturnSelf();
        Log::shouldReceive('info')->once()->andReturnUsing(function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $write();

        return $lines;
    }
}
