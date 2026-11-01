<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fixtures;

use ContextGate\Privacy\Pseudonymizer;

/** Counts the batch calls and the ids they carry. */
final class CountingPseudonymizer extends Pseudonymizer
{
    public int $calls = 0;

    public int $ids = 0;

    public function pseudonyms(string $prefix, string $table, array $ids, string $scope, ?string $caller = null): array
    {
        $this->calls++;
        $this->ids += count($ids);

        return parent::pseudonyms($prefix, $table, $ids, $scope, $caller);
    }
}
