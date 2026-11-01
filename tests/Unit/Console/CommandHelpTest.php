<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Console;

use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;

/** Every gate:* command and option explains itself in --help. */
class CommandHelpTest extends TestCase
{
    #[Test]
    public function every_gate_command_and_option_has_a_description(): void
    {
        $gate = array_filter(Artisan::all(), static fn (string $name): bool => str_starts_with($name, 'gate:'), ARRAY_FILTER_USE_KEY);

        $this->assertGreaterThanOrEqual(9, count($gate));

        foreach ($gate as $name => $command) {
            $this->assertNotSame('', $command->getDescription(), "{$name} has no description");

            foreach ($command->getDefinition()->getOptions() as $option) {
                $this->assertNotSame('', $option->getDescription(), "{$name} --{$option->getName()} has no description");
            }

            foreach ($command->getDefinition()->getArguments() as $argument) {
                $this->assertNotSame('', $argument->getDescription(), "{$name} {$argument->getName()} has no description");
            }
        }
    }
}
