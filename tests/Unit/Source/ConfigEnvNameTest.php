<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Source;

use ContextGate\Source\ConfigSource;
use ContextGate\Source\SourceRequest;
use ContextGate\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** FR-008, research R4: the env name is shown only when neither the key nor the variable is sensitive. */
class ConfigEnvNameTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.host', 'localhost');
        config()->set('services.stripe.secret', 'FAKE_FIXTURE_ONLY');
        config()->set('mail.host', 'smtp.example.test');
        config()->set('app.name', 'Demo');
        config()->set('app.debug', false);
        config()->set('app.api', 'Demo');
        config()->set('context-gate.sources.config.env_map', [
            'database.host' => 'DB_HOST',
            'services.stripe.secret' => 'STRIPE_SECRET',
            'mail.host' => 'MAIL_PASSWORD',
            'app.name' => 'APP_NAME',
            'app.api' => 'Api_TOKEN_URL',
        ]);
    }

    /** @return array<string, array{string, string|null}> key => expected env field */
    public static function cases(): array
    {
        return [
            'plain key and plain variable' => ['database.host', 'env:DB_HOST'],
            'sensitive key' => ['services.stripe.secret', 'env:<hidden>'],
            'sensitive variable only' => ['mail.host', 'env:<hidden>'],
            'sensitive word in the variable, any case' => ['app.api', 'env:<hidden>'],
            'literal value with no variable' => ['app.debug', null],
            'plain key and plain variable, second' => ['app.name', 'env:APP_NAME'],
        ];
    }

    #[DataProvider('cases')]
    public function test_env_field_follows_the_sensitive_name_rule(string $key, ?string $expected): void
    {
        $result = (new ConfigSource())->read(new SourceRequest(['prefix' => $key]));

        $this->assertTrue($result->ok);
        $this->assertIsArray($result->data['keys']);
        $entry = $result->data['keys'][0] ?? null;
        $this->assertIsArray($entry);
        $this->assertSame($expected, $entry['env']);
    }
}
