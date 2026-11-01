<?php

declare(strict_types=1);

namespace ContextGate\Contracts;

use ContextGate\Runbook\RunbookStep;

/**
 * A named, ordered list of read-only steps written in the host application. Register it under
 * `runbooks` in configuration; it is then runnable with gate:runbook and exposed as runbook.<name>.
 *
 * @api Supported extension surface: implement this interface for each runbook.
 */
interface Runbook
{
    public function name(): string;

    public function description(): string;

    /** @return list<RunbookStep> 1 to 20 steps, in the order they appear in the report */
    public function steps(): array;
}
