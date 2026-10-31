<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Server\ReasonCode;
use ContextGate\Server\Sources\ConfigKeysTool;
use ContextGate\Source\SourceFailure;
use ContextGate\Tests\TestCase;

/** spec 003 US3: a refused config.keys call is a refusal, not an empty success (same as the other source tools). */
class ConfigKeysRefusalTest extends TestCase
{
    public function test_a_non_string_prefix_throws_a_source_failure_with_input_invalid(): void
    {
        try {
            (new ConfigKeysTool())->handle(['prefix' => 5]);
            $this->fail('A non-string prefix must not return a success.');
        } catch (SourceFailure $failure) {
            $this->assertSame(ReasonCode::InputInvalid, $failure->reason);
        }
    }
}
