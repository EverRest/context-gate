<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Console;

use ContextGate\Contract\ContextContract;
use ContextGate\Pipeline\Caps;
use ContextGate\Tests\Fixtures\DayRelationProbeContract;
use ContextGate\Tests\Fixtures\RelationProbeContract;
use ContextGate\Tests\Fixtures\TextRelationProbeContract;
use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Testing\PendingCommand;

/** SC-005 / FR-025: each seeded configuration error makes gate:check exit 1 and name the problem. */
class CheckCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // spec 006 FR-011: gate:check reads the token tables, which the test application has only once migrated.
        TokenFactory::migrate();
    }

    /**
     * @param class-string<ContextContract> $contract
     * @param array<string, array<string, string>> $classification
     */
    private function configure(string $contract, array $classification): void
    {
        config(['context-gate.tools' => ['probe' => ['contract' => $contract]]]);
        config(['context-gate.classification' => $classification]);
    }

    private function check(): PendingCommand
    {
        $command = $this->artisan('gate:check');
        if (! $command instanceof PendingCommand) {
            $this->fail('artisan() did not return a PendingCommand.');
        }

        return $command;
    }

    public function test_a_valid_configuration_exits_0(): void
    {
        $this->configure(RelationProbeContract::class, [
            'orders' => ['status' => 'public', 'customer_id' => 'internal'],
            'customers' => ['name' => 'public'],
        ]);

        $this->check()->assertExitCode(0)->execute();
    }

    public function test_an_unclassified_contract_column_exits_1_and_names_it(): void
    {
        $this->configure(RelationProbeContract::class, [
            'orders' => ['status' => 'public'],
            'customers' => ['name' => 'public'],
        ]);

        $this->check()->assertExitCode(1)->expectsOutputToContain('orders.customer_id')->execute();
    }

    public function test_a_related_text_field_exits_1_and_names_the_rule(): void
    {
        $this->configure(TextRelationProbeContract::class, [
            'orders' => ['customer_id' => 'internal'],
            'customers' => ['customer' => 'public', 'email' => 'internal'],
        ]);

        $this->check()->assertExitCode(1)->expectsOutputToContain('contract.related_text')->execute();
    }

    public function test_a_day_scoped_related_pseudonym_exits_1_and_names_the_rule(): void
    {
        $this->configure(DayRelationProbeContract::class, [
            'orders' => ['status' => 'public', 'customer_id' => 'internal'],
            'customers' => ['name' => 'personal'],
        ]);

        $this->check()->assertExitCode(1)->expectsOutputToContain('contract.related_pseudonym_scope')->execute();
    }

    public function test_an_enabled_tool_without_a_contract_exits_1_and_names_it(): void
    {
        $this->configure(RelationProbeContract::class, [
            'orders' => ['status' => 'public', 'customer_id' => 'internal'],
            'customers' => ['name' => 'public'],
        ]);
        config(['context-gate.tools' => ['probe' => ['enabled' => true, 'class' => 'App\\Tools\\Probe']]]);

        $this->check()->assertExitCode(1)->expectsOutputToContain('tools.probe: enabled tool has no contract')->execute();
    }

    public function test_a_disabled_tool_without_a_contract_exits_0(): void
    {
        $this->configure(RelationProbeContract::class, [
            'orders' => ['status' => 'public', 'customer_id' => 'internal'],
            'customers' => ['name' => 'public'],
        ]);
        config(['context-gate.tools' => ['probe' => ['enabled' => false, 'class' => 'App\\Tools\\Probe']]]);

        $this->check()->assertExitCode(0)->execute();
    }

    public function test_an_unknown_ceiling_exits_1_and_names_the_key(): void
    {
        $this->configure(RelationProbeContract::class, [
            'orders' => ['status' => 'public', 'customer_id' => 'internal'],
            'customers' => ['name' => 'public'],
        ]);
        config(['context-gate.firewall.ceiling' => 'secretive']);

        $this->check()->assertExitCode(1)->expectsOutputToContain('firewall.ceiling')->execute();
    }

    public function test_a_limit_above_its_cap_exits_1_and_names_the_key(): void
    {
        $this->configure(RelationProbeContract::class, [
            'orders' => ['status' => 'public', 'customer_id' => 'internal'],
            'customers' => ['name' => 'public'],
        ]);
        config(['context-gate.firewall.limits.per_call_bytes' => Caps::PER_CALL + 1]);

        $this->check()->assertExitCode(1)->expectsOutputToContain('firewall.limits.per_call_bytes')->execute();
    }

    public function test_an_empty_string_next_to_a_real_canary_exits_1_and_names_the_key(): void
    {
        $this->configure(RelationProbeContract::class, [
            'orders' => ['status' => 'public', 'customer_id' => 'internal'],
            'customers' => ['name' => 'public'],
        ]);
        config(['context-gate.canaries.values' => ['', 'canary-7f3a@example.invalid']]);

        $this->check()->assertExitCode(1)->expectsOutputToContain('canaries.values[0]')->execute();
    }

    public function test_unset_canary_values_warn_and_exit_0(): void
    {
        $this->configure(RelationProbeContract::class, [
            'orders' => ['status' => 'public', 'customer_id' => 'internal'],
            'customers' => ['name' => 'public'],
        ]);
        config(['context-gate.canaries.values' => []]);

        $this->check()
            ->assertExitCode(0)
            ->expectsOutputToContain('WARN canaries: no canary values set; canary protection is off')
            ->execute();
    }

    public function test_empty_string_canary_values_warn_and_exit_0(): void
    {
        $this->configure(RelationProbeContract::class, [
            'orders' => ['status' => 'public', 'customer_id' => 'internal'],
            'customers' => ['name' => 'public'],
        ]);
        config(['context-gate.canaries.values' => ['']]);

        $this->check()
            ->assertExitCode(0)
            ->expectsOutputToContain('WARN canaries: no canary values set; canary protection is off')
            ->execute();
    }

    public function test_a_null_canary_value_warns_and_exits_0(): void
    {
        $this->configure(RelationProbeContract::class, [
            'orders' => ['status' => 'public', 'customer_id' => 'internal'],
            'customers' => ['name' => 'public'],
        ]);
        config(['context-gate.canaries.values' => [null]]);

        $this->check()
            ->assertExitCode(0)
            ->expectsOutputToContain('WARN canaries: no canary values set; canary protection is off')
            ->execute();
    }

    public function test_a_null_canary_next_to_a_real_canary_exits_0(): void
    {
        $this->configure(RelationProbeContract::class, [
            'orders' => ['status' => 'public', 'customer_id' => 'internal'],
            'customers' => ['name' => 'public'],
        ]);
        config(['context-gate.canaries.values' => [null, 'canary-7f3a@example.invalid']]);

        $this->check()->assertExitCode(0)->execute();
    }

    public function test_a_non_string_canary_value_exits_1_and_names_the_key(): void
    {
        $this->configure(RelationProbeContract::class, [
            'orders' => ['status' => 'public', 'customer_id' => 'internal'],
            'customers' => ['name' => 'public'],
        ]);
        config(['context-gate.canaries.values' => [42]]);

        $this->check()->assertExitCode(1)->expectsOutputToContain('canaries.values[0]')->execute();
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function badSources(): array
    {
        return [
            'docs path with a parent segment' => [['docs' => ['paths' => ['docs/../']]], 'sources.docs.paths'],
            'docs path that is absolute' => [['docs' => ['paths' => ['/etc/']]], 'sources.docs.paths'],
            'docs path that is a glob' => [['docs' => ['paths' => ['docs/*']]], 'sources.docs.paths'],
            'docs path folder without a slash' => [['docs' => ['paths' => ['src']]], 'sources.docs.paths'],
            'openapi path with a parent segment' => [['openapi' => ['paths' => ['../x.json']]], 'sources.openapi.paths'],
            'model that is not a model' => [['eloquent' => ['models' => [\stdClass::class]]], 'sources.eloquent.models'],
            'env map value that is not a string' => [['config' => ['env_map' => ['app.name' => 5]]], 'sources.config.env_map'],
        ];
    }

    /** @param array<string, mixed> $sources */
    #[\PHPUnit\Framework\Attributes\DataProvider('badSources')]
    public function test_a_bad_sources_entry_exits_1_and_names_the_key(array $sources, string $key): void
    {
        $this->configure(RelationProbeContract::class, [
            'orders' => ['status' => 'public', 'customer_id' => 'internal'],
            'customers' => ['name' => 'public'],
        ]);
        config(['context-gate.sources' => $sources]);
        $this->app?->setBasePath(dirname(__DIR__, 3));

        $this->check()->assertExitCode(1)->expectsOutputToContain($key)->execute();
    }

    public function test_an_enabled_source_tool_without_its_catalog_table_exits_1(): void
    {
        config(['context-gate.classification' => []]);
        config(['context-gate.tools' => ['docs.read' => ['enabled' => true] + \ContextGate\Server\SourceTools::entries()['docs.read']]]);

        $this->check()->assertExitCode(1)->expectsOutputToContain('source_docs')->execute();
    }
}
