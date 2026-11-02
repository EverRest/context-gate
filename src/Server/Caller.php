<?php

declare(strict_types=1);

namespace ContextGate\Server;

use ContextGate\Settings\ActiveSettings;
use ContextGate\Classification\Ceiling;
use Throwable;

final readonly class Caller
{
    /** @param list<string> $tools */
    public function __construct(
        public string $id,
        public array $tools,
    ) {
    }

    public static function current(): self
    {
        $id = app('context-gate.caller');

        $settings = app(ActiveSettings::class);

        // An invalid document grants nothing; the call is refused as config.invalid before any tool is looked up.
        return new self($id, $settings->valid() ? ($settings->callers()[$id] ?? []) : []);
    }

    /** For the last-resort handlers: the caller, or a placeholder when even that lookup fails. */
    public static function orUnknown(): self
    {
        try {
            return self::current();
        } catch (Throwable) {
            return new self('unknown', []);
        }
    }

    /**
     * The ceiling of this call. Over HTTP the server binds the token's ceiling in the container ('' when the token
     * has none; a null instance would read as unbound); otherwise the configured one, for stdio. Null means fail
     * closed: GateCallTool refuses the call as config.invalid.
     */
    public static function ceiling(): ?Ceiling
    {
        if (! app()->bound('context-gate.ceiling')) {
            $settings = app(ActiveSettings::class);

            return $settings->valid() ? $settings->ceiling() : null;
        }

        $value = app('context-gate.ceiling');

        return is_string($value) ? Ceiling::tryFrom($value) : null;
    }
}
