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

        return new self($id, config("context-gate.callers.{$id}.tools", []));
    }
}
