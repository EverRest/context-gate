<?php

declare(strict_types=1);

namespace ContextGate\Policy;

use ContextGate\Server\Caller;
use Illuminate\Support\Facades\Gate;

final class ToolPolicy
{
    /** @param array<string, mixed> $arguments */
    public function allows(string $tool, Caller $caller, array $arguments): bool
    {
        $ability = config("context-gate.tools.{$tool}.gate");

        // A tool without a defined ability is denied (deny by default).
        // The caller is the Gate user; the validated arguments are wrapped so Gate does not spread their keys.
        return is_string($ability) && Gate::has($ability) && Gate::forUser($caller)->allows($ability, [$arguments]);
    }
}
