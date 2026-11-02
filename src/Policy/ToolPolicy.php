<?php

declare(strict_types=1);

namespace ContextGate\Policy;

use ContextGate\Settings\ActiveSettings;
use ContextGate\Contracts\AuthorizesTools;
use ContextGate\Server\Caller;
use Illuminate\Support\Facades\Gate;

/** @internal Do not extend to customise: bind your own implementation of AuthorizesTools instead. */
class ToolPolicy implements AuthorizesTools
{
    /** @param array<string, mixed> $arguments */
    public function allows(string $tool, Caller $caller, array $arguments): bool
    {
        $ability = (app(ActiveSettings::class)->tools()[$tool] ?? null)?->gate;

        // A tool without a defined ability is denied (deny by default).
        // The caller is the Gate user; the validated arguments are wrapped so Gate does not spread their keys.
        return is_string($ability) && Gate::has($ability) && Gate::forUser($caller)->allows($ability, [$arguments]);
    }
}
