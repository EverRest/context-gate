<?php

declare(strict_types=1);

namespace ContextGate\Contracts;

/**
 * Issues and resolves keyed pseudonyms for record ids.
 *
 * Contract: stable per (scope, id, caller), never returns or embeds the raw id, resolve() returns null when the
 * pseudonym is unknown or expired and never resolves one issued to another caller. The implementation is trusted:
 * it is part of the security path, and the package cannot detect one that leaks the raw id.
 *
 * @api Supported extension surface: bind your own implementation instead of extending the concrete Pseudonymizer.
 */
interface IssuesPseudonyms
{
    public function pseudonym(string $prefix, string $table, string $id, string $scope, ?string $caller = null): string;

    /**
     * @param list<string> $ids
     * @return array<string, string> id => pseudonym
     */
    public function pseudonyms(string $prefix, string $table, array $ids, string $scope, ?string $caller = null): array;

    /** @return array{table: string, id: string}|null null when the pseudonym was never issued or has expired */
    public function resolve(string $pseudonym, string $scope, ?string $caller = null): ?array;
}
