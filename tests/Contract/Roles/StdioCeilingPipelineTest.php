<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Roles;

use ContextGate\Contract\ContextContract;
use ContextGate\Contract\Field;
use ContextGate\Tests\Contract\GateCallHarness;
use ContextGate\Tests\Fixtures\CustomerRecord;
use ContextGate\Tests\Fixtures\ProbeTool;
use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Gate;

/** spec 010 FR-004, US4 scenario 3: over stdio the response is filtered by the narrower of firewall.ceiling and the role's ceiling, through the whole pipeline. */
class StdioCeilingPipelineTest extends TestCase
{
    use GateCallHarness;

    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
        $this->recordLines();
        Gate::define('echo.allow', fn (): bool => true);
        ProbeTool::$called = false;
        ProbeTool::$output = '[{"status":"paid","note":"a note"}]';
        $this->configureTool(StdioCeilingContract::class, ['customers' => ['status' => 'public', 'note' => 'internal']]);
        app()->instance('context-gate.caller', 'alice');
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        $result = $this->callTool('probe', ['text' => 'x']);

        return json_decode((string) $result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_the_firewall_ceiling_alone_shows_an_internal_field(): void
    {
        config(['context-gate.firewall.ceiling' => 'internal', 'context-gate.roles.alice.ceiling' => 'personal']);

        $this->assertSame([['status' => 'paid', 'note' => 'a note']], $this->rows());
    }

    public function test_a_public_role_drops_the_internal_field_even_though_the_firewall_ceiling_is_internal(): void
    {
        config(['context-gate.firewall.ceiling' => 'internal', 'context-gate.roles.alice.ceiling' => 'public']);

        $this->assertSame([['status' => 'paid']], $this->rows());
    }
}

final class StdioCeilingContract extends ContextContract
{
    public function fields(): array
    {
        return ['status' => Field::plain(), 'note' => Field::plain()];
    }

    public function model(): string
    {
        return CustomerRecord::class;
    }
}
