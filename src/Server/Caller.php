<?php

declare(strict_types=1);

namespace ContextGate\Server;

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
}
