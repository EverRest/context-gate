<?php

declare(strict_types=1);

namespace ContextGate\Server;

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

        // Read by key: a dot in the OS user name would otherwise be treated as config nesting.
        $tools = config('context-gate.callers', [])[$id]['tools'] ?? [];

        return new self($id, is_array($tools) ? array_values(array_filter($tools, is_string(...))) : []);
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
        $value = app()->bound('context-gate.ceiling')
            ? app('context-gate.ceiling')
            : config('context-gate.firewall.ceiling', Ceiling::Internal->value);

        return is_string($value) ? Ceiling::tryFrom($value) : null;
    }
}
