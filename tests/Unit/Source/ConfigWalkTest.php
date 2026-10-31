<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Source;

use ContextGate\Server\ReasonCode;
use ContextGate\Source\ConfigSource;
use ContextGate\Source\SourceRequest;
use ContextGate\Tests\TestCase;

/** FR-007, research R4: dotted keys with leaf types; array items are never walked. */
class ConfigWalkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('walk', [
            'string' => 'FAKE_FIXTURE_ONLY',
            'int' => 3,
            'float' => 1.5,
            'bool' => true,
            'null' => null,
            'items' => ['FAKE_FIXTURE_ONLY', 2],
            'nested' => ['leaf' => 'FAKE_FIXTURE_ONLY'],
        ]);
    }

    public function test_keys_are_dotted_and_typed_by_leaf(): void
    {
        $this->assertSame([
            'walk.bool' => 'bool',
            'walk.float' => 'float',
            'walk.int' => 'int',
            'walk.items' => 'array',
            'walk.nested.leaf' => 'string',
            'walk.null' => 'null',
            'walk.string' => 'string',
        ], $this->keyTypes('walk'));
    }

    public function test_array_items_are_not_walked(): void
    {
        $keys = array_keys($this->keyTypes('walk'));

        $this->assertNotContains('walk.items.0', $keys);
        $this->assertNotContains('walk.items.1', $keys);
    }

    public function test_each_entry_keeps_only_key_type_and_env(): void
    {
        $result = (new ConfigSource())->read(new SourceRequest(['prefix' => 'walk']));

        $this->assertTrue($result->ok);
        $this->assertIsArray($result->data['keys']);
        foreach ($result->data['keys'] as $entry) {
            $this->assertIsArray($entry);
            $this->assertSame(['key', 'type', 'env'], array_keys($entry));
        }
    }

    public function test_prefix_limits_the_walk_to_that_branch(): void
    {
        $this->assertSame(['walk.nested.leaf' => 'string'], $this->keyTypes('walk.nested'));
    }

    public function test_non_string_prefix_is_refused_as_invalid_input(): void
    {
        $result = (new ConfigSource())->read(new SourceRequest(['prefix' => 5]));

        $this->assertFalse($result->ok);
        $this->assertSame(ReasonCode::InputInvalid, $result->reason);
    }

    /** @return array<string, string> key => type */
    private function keyTypes(string $prefix): array
    {
        $result = (new ConfigSource())->read(new SourceRequest(['prefix' => $prefix]));
        $this->assertTrue($result->ok);
        $this->assertIsArray($result->data['keys']);

        $types = [];
        foreach ($result->data['keys'] as $entry) {
            $this->assertIsArray($entry);
            $this->assertIsString($entry['key']);
            $this->assertIsString($entry['type']);
            $types[$entry['key']] = $entry['type'];
        }
        ksort($types);

        return $types;
    }
}
