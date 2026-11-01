<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Server;

use ContextGate\Server\ArgumentValidator;
use ContextGate\Tests\Fixtures\EchoTool;
use ContextGate\Server\Tool;
use ContextGate\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

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

    /** @param array<string, mixed> $schema */
    private function tool(array $schema): Tool
    {
        return new class ($schema) extends EchoTool {
            /** @param array<string, mixed> $schema */
            public function __construct(private array $schema)
            {
            }

            public function schema(): array
            {
                return ['type' => 'object', 'properties' => ['v' => $this->schema], 'required' => ['v']];
            }
        };
    }

    /** @return array<string, array{array<string, mixed>, mixed, bool}> */
    public static function cases(): array
    {
        $nested = ['type' => 'object', 'properties' => ['n' => ['type' => 'integer']], 'required' => ['n']];
        $list = ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 3]];

        return [
            'nested ok' => [$nested, ['n' => 1], true],
            'nested wrong type' => [$nested, ['n' => 'x'], false],
            'nested missing required' => [$nested, [], false],
            'nested unknown key' => [$nested, ['n' => 1, 'z' => 1], false],
            'items ok' => [$list, ['abc', 'de'], true],
            'items bad element' => [$list, ['abc', 'abcd'], false],
            'items not a list' => [$list, 'abc', false],
            'enum ok' => [['type' => 'string', 'enum' => ['a', 'b']], 'a', true],
            'enum bad' => [['type' => 'string', 'enum' => ['a', 'b']], 'c', false],
            'maxLength bad' => [['type' => 'string', 'maxLength' => 2], 'abc', false],
            'minLength bad' => [['type' => 'string', 'minLength' => 2], 'a', false],
            'pattern ok' => [['type' => 'string', 'pattern' => '^[a-z]+$'], 'abc', true],
            'pattern bad' => [['type' => 'string', 'pattern' => '^[a-z]+$'], 'ab1', false],
            'union ok' => [['type' => ['string', 'null']], null, true],
            'union bad' => [['type' => ['string', 'null']], 5, false],
            'minimum bad' => [['type' => 'integer', 'minimum' => 1], 0, false],
            'maximum bad' => [['type' => 'number', 'maximum' => 1], 1.5, false],
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    #[DataProvider('cases')]
    public function test_nested_and_keyword_validation(array $schema, mixed $value, bool $valid): void
    {
        $error = (new ArgumentValidator())->validate($this->tool($schema), ['v' => $value]);

        $valid ? $this->assertNull($error) : $this->assertNotNull($error);
    }
}
