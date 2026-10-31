<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Server;

use ContextGate\Server\ReasonCode;
use ContextGate\Server\Sources\ConfigKeysTool;
use ContextGate\Source\SourceFailure;
use ContextGate\Tests\TestCase;

class ConfigKeysToolTest extends TestCase
{
    public function test_a_non_string_prefix_fails_as_input_invalid_not_as_an_empty_list(): void
    {
        try {
            (new ConfigKeysTool())->handle(['prefix' => 5]);
            $this->fail('An invalid prefix must fail');
        } catch (SourceFailure $failure) {
            $this->assertSame(ReasonCode::InputInvalid, $failure->reason);
        }
    }
}
