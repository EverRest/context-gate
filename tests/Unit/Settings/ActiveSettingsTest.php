<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Settings;

use ContextGate\Pipeline\Caps;
use ContextGate\Settings\ActiveSettings;
use ContextGate\Settings\SettingsValidator;
use PHPUnit\Framework\TestCase;

/** spec 007 T017 (R8): the document is validated once, when the instance is built. */
class ActiveSettingsTest extends TestCase
{
    public function test_a_valid_document_is_valid_with_no_violations(): void
    {
        $settings = new ActiveSettings(['profile' => 'strict'], 'production');

        $this->assertTrue($settings->valid());
        $this->assertSame([], $settings->violations());
    }

    public function test_an_invalid_document_is_not_valid_and_lists_the_violations(): void
    {
        $settings = new ActiveSettings(['firewall' => ['limits' => ['per_call_bytes' => Caps::PER_CALL + 1]]], 'production');

        $this->assertFalse($settings->valid());
        $this->assertSame(['firewall.limits.per_call_bytes'], array_column($settings->violations(), 'key'));
    }

    public function test_the_result_is_the_validator_result_for_the_same_document(): void
    {
        $document = ['profile' => 'balanced'];
        $settings = new ActiveSettings($document, 'production');

        $this->assertSame(SettingsValidator::violations($document, 'production'), $settings->violations());
    }
}
