<?php

declare(strict_types=1);

namespace ContextGate\Console;

use Symfony\Component\Console\Output\ConsoleOutputInterface;

/** @internal May change without notice. */
trait FailsToStderr
{
    /** One actionable line: on stderr under --json (stdout stays pure JSON), else the usual error line. */
    private function failWith(string $message): int
    {
        if ($this->option('json')) {
            $out = $this->output->getOutput();
            ($out instanceof ConsoleOutputInterface ? $out->getErrorOutput() : $out)->writeln($message);
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
