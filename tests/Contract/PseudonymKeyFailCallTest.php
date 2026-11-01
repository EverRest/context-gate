<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\Fixtures\DayPseudonymCustomerContract;
use ContextGate\Tests\Fixtures\ProbeTool;
use ContextGate\Tests\TestCase;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;

/** F3 and item 4: a pseudonym key failure is pseudonym.keyfail, answered with the failed reference, not a refusal. */
class PseudonymKeyFailCallTest extends TestCase
{
    use GateCallHarness;

    public function test_a_pseudonym_state_the_store_refuses_to_keep_fails_with_a_reference_and_keyfail(): void
    {
        $this->recordLines();
        $this->configureTool(DayPseudonymCustomerContract::class, ['customers' => ['name' => 'personal']]);
        // The store refuses the pseudonym state (e.g. a value over its size limit): fail closed.
        $store = new class () extends ArrayStore {
            public function put($key, $value, $seconds)
            {
                return str_starts_with($key, 'context-gate:pseudonym:') ? false : parent::put($key, $value, $seconds);
            }
        };
        Cache::extend('refusing', fn () => Cache::repository($store));
        config(['cache.stores.refusing' => ['driver' => 'refusing'], 'cache.default' => 'refusing']);
        Cache::forgetDriver('refusing');
        Cache::forgetDriver('array');
        ProbeTool::$output = (string) json_encode([['id' => 5, 'name' => 'Ann']]);

        $result = $this->callTool('probe', ['text' => 'x']);

        $this->assertTrue($result['isError']);
        $this->assertMatchesRegularExpression('/^Request failed\. Reference: r_[0-9a-f]{8}$/', $result['content'][0]['text']);
        $receipt = $this->lastReceipt();
        $this->assertSame('pseudonym.keyfail', $receipt['reason']);
        $this->assertSame('failed', $receipt['decision']);
    }
}
