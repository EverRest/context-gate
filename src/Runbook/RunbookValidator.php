<?php

declare(strict_types=1);

namespace ContextGate\Runbook;

use ContextGate\Contracts\Runbook;
use ContextGate\Pipeline\Caps;
use Throwable;

/**
 * The runbook rules that gate:check and the tool share: the class exists and implements Runbook, the
 * steps can be built, 1 to 20 steps, unique names, every step declared read-only. A step's timeout and output size are
 * checked by RunbookStep when it is built; a step that breaks them makes steps() throw, which is reported here.
 * The fingerprint is added by the check and the tool (RunbookFingerprint), not here.
 *
 * @internal May change without notice.
 */
final class RunbookValidator
{
    /** @return list<array{key: string, message: string}> every rule a runbook breaks, with the runbook key */
    public static function violations(string $key, mixed $class): array
    {
        if (! is_string($class) || ! class_exists($class)) {
            return [self::violation($key, 'class does not exist')];
        }

        if (! is_a($class, Runbook::class, true)) {
            return [self::violation($key, 'class must implement '.Runbook::class)];
        }

        try {
            $runbook = app($class);
            $steps = $runbook->steps();
        } catch (Throwable $e) {
            // The class only: a host message may hold a value (constitution VII). An anonymous class name carries a NUL
            // and the absolute path after the first NUL, so only the part before it is printed (as Failure::note does).
            return [self::violation($key, 'steps() threw '.explode("\0", $e::class)[0])];
        }

        return self::stepViolations($key, $steps);
    }

    /**
     * @param list<mixed> $steps
     * @return list<array{key: string, message: string}>
     */
    private static function stepViolations(string $key, array $steps): array
    {
        $messages = [];
        $count = count($steps);

        if ($count === 0) {
            $messages[] = 'has no steps';
        } elseif ($count > Caps::RUNBOOK_STEPS) {
            $messages[] = "has {$count} steps; the limit is ".Caps::RUNBOOK_STEPS;
        }

        $names = [];

        foreach ($steps as $step) {
            if (! $step instanceof RunbookStep) {
                $messages[] = 'every step must be a RunbookStep';

                continue;
            }

            if (isset($names[$step->name])) {
                $messages[] = "step name [{$step->name}] is used more than once";
            }

            $names[$step->name] = true;

            if (! $step->readOnly) {
                $messages[] = "step [{$step->name}] is not declared read-only";
            }
        }

        return array_map(fn (string $message): array => self::violation($key, $message), $messages);
    }

    /** @return array{key: string, message: string} */
    private static function violation(string $key, string $message): array
    {
        return ['key' => $key, 'message' => $message];
    }
}
