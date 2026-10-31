<?php

declare(strict_types=1);

namespace ContextGate\Source;

interface Source
{
    public function name(): string;

    public function read(SourceRequest $request): SourceResult;
}
