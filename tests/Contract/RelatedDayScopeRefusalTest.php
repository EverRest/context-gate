<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\Fixtures\DayRelationProbeContract;
use ContextGate\Tests\Fixtures\ProbeTool;
use ContextGate\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S2: a related day or week pseudonym has no key of its own, so the call is refused, never emitted. The contract
 * is not validated at load here: the runtime refusal must hold on its own, with the load-time rule as the first line.
 */
class RelatedDayScopeRefusalTest extends TestCase
{
    use GateCallHarness;

    public function test_a_relation_to_a_day_scoped_pseudonym_is_refused_with_pseudonym_unknown(): void
    {
        $this->recordLines();
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        DB::table('customers')->insert(['id' => 1, 'name' => 'Ada']);
        $this->configureTool(DayRelationProbeContract::class, [
            'orders' => ['status' => 'public', 'customer_id' => 'internal'],
            'customers' => ['id' => 'public', 'name' => 'public'],
        ]);
        ProbeTool::$output = (string) json_encode([['status' => 'paid', 'customer_id' => 1]]);

        $result = $this->callTool('probe', ['text' => 'x']);

        $this->assertTrue($result['isError']);
        $this->assertSame('Request refused.', $result['content'][0]['text']);
        $receipt = json_decode((string) end($this->lines), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('pseudonym.unknown', $receipt['reason']);
    }
}
