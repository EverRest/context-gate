<?php

declare(strict_types=1);

namespace ContextGate\Server;

use ContextGate\Pipeline\Payload;

/**
 * The state of one gate:preview run, bound in the container for its duration only. Every receipt written during the
 * run carries the source, and the outcome of the run (the reason it stopped, or the payload with its dropped fields)
 * is kept for the command to print.
 */
final class Preview
{
    public string $source = 'preview';

    public ?ReasonCode $reason = null;

    public ?Payload $payload = null;

    public function record(?ReasonCode $reason, ?Payload $payload): void
    {
        if ($reason !== null) {
            $this->reason = $reason;
        }

        if ($payload !== null) {
            $this->payload = $payload;
        }
    }
}
