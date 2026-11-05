<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\Fixtures\CanaryValues;
use ContextGate\Tests\Fixtures\CustomerContract;
use ContextGate\Tests\Fixtures\ProbeTool;
use ContextGate\Tests\Fixtures\RelationProbeContract;
use ContextGate\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** R8 with F1: a canary in a plain field, a related row or a fullwidth form is found after the same normalisation. */
class CanaryPlainFieldTest extends TestCase
{
    use GateCallHarness;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
        });
    }

    public function test_a_canary_in_a_plain_field_split_by_a_zero_width_space_is_refused(): void
    {
        $this->recordLines();
        $this->configureTool(CustomerContract::class, ['customers' => ['customer' => 'internal', 'email' => 'internal']]);
        config(['context-gate.canaries.values' => [CanaryValues::TOKEN]]);
        $split = substr(CanaryValues::TOKEN, 0, 7)."\u{200B}".substr(CanaryValues::TOKEN, 7);
        ProbeTool::$output = (string) json_encode([['customer' => $split, 'email' => 'a@example.invalid']]);

        $result = $this->callTool('probe', ['text' => 'x']);

        $this->assertTrue($result['isError']);
        $this->assertMatchesRegularExpression('/^Request failed\. Reference: r_[0-9a-f]{16}$/', $result['content'][0]['text']);
        $this->assertStringNotContainsString('CANARY', (string) json_encode($result));
        $receipt = json_decode((string) end($this->lines), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('canary.hit', $receipt['reason']);
    }

    public function test_a_fullwidth_canary_in_a_plain_field_is_refused(): void
    {
        $this->recordLines();
        $this->configureTool(CustomerContract::class, ['customers' => ['customer' => 'internal', 'email' => 'internal']]);
        config(['context-gate.canaries.values' => [CanaryValues::TOKEN]]);
        ProbeTool::$output = (string) json_encode([['customer' => "\u{FF23}\u{FF21}\u{FF2E}\u{FF21}\u{FF32}\u{FF39}_TOKEN_0c9e1a", 'email' => 'a@example.invalid']]);

        $result = $this->callTool('probe', ['text' => 'x']);

        $this->assertTrue($result['isError']);
        $receipt = json_decode((string) end($this->lines), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('canary.hit', $receipt['reason']);
    }

    public function test_a_canary_split_inside_a_related_row_is_refused(): void
    {
        $this->recordLines();
        DB::table('customers')->insert(['id' => 1, 'name' => substr(CanaryValues::TOKEN, 0, 7)."\u{200B}".substr(CanaryValues::TOKEN, 7), 'email' => null]);
        $this->configureTool(RelationProbeContract::class, ['orders' => ['status' => 'public', 'customer_id' => 'internal'], 'customers' => ['name' => 'internal']]);
        config(['context-gate.canaries.values' => [CanaryValues::TOKEN]]);
        ProbeTool::$output = (string) json_encode([['status' => 'paid', 'customer_id' => 1]]);

        $result = $this->callTool('probe', ['text' => 'x']);

        $this->assertTrue($result['isError']);
        $this->assertStringNotContainsString('CANARY', (string) json_encode($result));
        $receipt = json_decode((string) end($this->lines), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('canary.hit', $receipt['reason']);
    }

    public function test_a_plain_string_is_nfkc_normalised_in_the_response(): void
    {
        $this->recordLines();
        $this->configureTool(CustomerContract::class, ['customers' => ['customer' => 'internal', 'email' => 'internal']]);
        ProbeTool::$output = (string) json_encode([['customer' => "\u{FF21}\u{FF28}ello\u{200B}", 'email' => 'a@example.invalid']]);

        $result = $this->callTool('probe', ['text' => 'x']);

        $this->assertFalse($result['isError']);
        $this->assertStringContainsString('"customer":"AHello"', $result['content'][0]['text']);
    }

    public function test_a_plain_string_with_a_fullwidth_letter_and_an_invisible_character_comes_back_ascii(): void
    {
        $this->recordLines();
        $this->configureTool(CustomerContract::class, ['customers' => ['customer' => 'internal', 'email' => 'internal']]);
        ProbeTool::$output = (string) json_encode([['customer' => "\u{FF21}\u{200B}B", 'email' => 'a@example.invalid']]);

        $result = $this->callTool('probe', ['text' => 'x']);

        $this->assertFalse($result['isError']);
        $this->assertStringContainsString('"customer":"AB"', $result['content'][0]['text']);
    }
}
