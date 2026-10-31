<?php

declare(strict_types=1);

namespace ContextGate\Contract;

/**
 * Declares which fields of one model an AI caller may receive. Classification is never set here:
 * it comes from the catalog, so a contract cannot widen what the catalog allows.
 */
abstract class ContextContract
{
    /** @return array<string, Field> column => form, in output order */
    abstract public function fields(): array;

    /** @return class-string Eloquent model class */
    abstract public function model(): string;
}
