<?php

declare(strict_types=1);

namespace ContextGate\Policy;

use ContextGate\Server\Caller;
use Illuminate\Support\Facades\Gate;

/** @api Supported extension surface: covered by the compatibility policy in the README. */
class ToolPolicy
{
    /** @param array<string, mixed> $arguments */
    public function allows(string $tool, Caller $caller, array $arguments): bool
    {
        // Read by key: a dot in the tool name would otherwise be treated as config nesting.
        $ability = config('context-gate.tools', [])[$tool]['gate'] ?? null;

        // A tool without a defined ability is denied (deny by default).
        // The caller is the Gate user; the validated arguments are wrapped so Gate does not spread their keys.
        return is_string($ability) && Gate::has($ability) && Gate::forUser($caller)->allows($ability, [$arguments]);
    }
}
