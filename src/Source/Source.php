<?php

declare(strict_types=1);

namespace ContextGate\Source;

/** @api Supported extension surface: covered by the compatibility policy in the README. */
interface Source
{
    public function name(): string;

    public function read(SourceRequest $request): SourceResult;
}
