<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Source\ConfigSource;
use ContextGate\Source\SourceRequest;
use ContextGate\Tests\TestCase;

/** FR-007, SC-001: a seeded config value never appears in the source response. */
class ConfigNoValuesTest extends TestCase
{
    private const SEEDED = 'FAKE_FIXTURE_ONLY';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.stripe.secret', self::SEEDED);
        config()->set('database.password', self::SEEDED);
        config()->set('app.items', [self::SEEDED]);
        config()->set('context-gate.sources.config.env_map', [
            'services.stripe.secret' => 'STRIPE_SECRET',
            'database.password' => 'DB_PASSWORD',
        ]);
    }

    public function test_seeded_value_is_absent_from_the_whole_response(): void
    {
        $result = (new ConfigSource())->read(new SourceRequest([]));

        $this->assertTrue($result->ok);
        $this->assertStringNotContainsString(self::SEEDED, (string) json_encode($result));
    }
}
