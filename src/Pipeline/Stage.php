<?php

declare(strict_types=1);

namespace ContextGate\Pipeline;

use Closure;

/**
 * One step of the firewall pipeline. A stage may return without calling $next to stop the pipeline.
 */
interface Stage
{
    /** @param Closure(Payload): Payload $next */
    public function handle(Payload $payload, Closure $next): Payload;
}
