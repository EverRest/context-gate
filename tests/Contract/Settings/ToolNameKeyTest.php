<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Settings;

use ContextGate\Settings\SettingsValidator;
use ContextGate\Tests\Fixtures\ProbeTool;
use ContextGate\Tests\TestCase;

/** spec 007 FR-016, research R9: callers are checked against the names the registry uses (Tool::name()), not the config keys. */
class ToolNameKeyTest extends TestCase
{
    public function test_a_tools_key_that_differs_from_the_tool_name_is_refused(): void
    {
        $keys = array_column(SettingsValidator::violations(['tools' => ['alias' => ['enabled' => true, 'class' => ProbeTool::class]]], 'testing'), 'key');

        $this->assertContains('tools.alias', $keys);
    }

    public function test_a_caller_is_checked_against_the_tool_name_and_not_the_key(): void
    {
        $tools = ['alias' => ['enabled' => true, 'class' => ProbeTool::class]];

        $byName = array_column(SettingsValidator::violations(['tools' => $tools, 'callers' => ['a' => ['tools' => ['probe']]]], 'testing'), 'key');
        $byKey = array_column(SettingsValidator::violations(['tools' => $tools, 'callers' => ['a' => ['tools' => ['alias']]]], 'testing'), 'key');

        $this->assertSame(['tools.alias'], $byName);
        $this->assertContains('callers.a.tools', $byKey);
    }
}
