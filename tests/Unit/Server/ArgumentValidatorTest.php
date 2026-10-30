<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Server;

use ContextGate\Server\ArgumentValidator;
use ContextGate\Tests\Fixtures\EchoTool;
use ContextGate\Tests\TestCase;

class ArgumentValidatorTest extends TestCase
{
    public function test_unknown_argument_key_is_rejected_and_named(): void
    {
        $error = (new ArgumentValidator())->validate(new EchoTool(), ['text' => 'hi', 'foo' => 'x']);

        $this->assertSame('Argument [foo] is not allowed for tool [echo].', $error);
    }

    public function test_value_violating_schema_is_rejected(): void
    {
        $error = (new ArgumentValidator())->validate(new EchoTool(), ['text' => 123]);

        $this->assertNotNull($error);
        $this->assertStringContainsString('text', $error);
    }

    public function test_valid_arguments_pass(): void
    {
        $this->assertNull((new ArgumentValidator())->validate(new EchoTool(), ['text' => 'hi']));
    }
}
