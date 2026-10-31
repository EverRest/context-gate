<?php

declare(strict_types=1);

namespace ContextGate\Contract;

use ContextGate\Classification\Ceiling;
use InvalidArgumentException;

/**
 * The form in which one catalog column reaches the caller. Factories take no classification:
 * the catalog decides which forms are allowed (see allows()).
 */
final class Field
{
    public const PLAIN = 'plain';

    public const PSEUDONYM = 'pseudonym';

    public const BUCKET = 'bucket';

    public const DATE = 'date';

    public const AGGREGATE = 'aggregate';

    public const TEXT = 'text';

    public const RELATION = 'relation';

    /** @param array<string, string|list<int|float>> $options */
    private function __construct(
        public readonly string $form,
        public readonly array $options = [],
    ) {
    }

    public static function plain(): self
    {
        return new self(self::PLAIN);
    }

    /** The scope is how long the pseudonym holds: session (default), day or week (FR-009). Nothing longer exists. */
    public static function pseudonym(string $prefix, string $scope = 'session'): self
    {
        if (! in_array($scope, ['session', 'day', 'week'], true)) {
            throw new InvalidArgumentException(sprintf('Pseudonym scope [%s] is not allowed.', $scope));
        }

        return new self(self::PSEUDONYM, ['prefix' => $prefix, 'scope' => $scope]);
    }

    /** @param list<int|float> $breaks */
    public static function bucket(array $breaks): self
    {
        return new self(self::BUCKET, ['breaks' => $breaks]);
    }

    public static function date(string $precision): self
    {
        return new self(self::DATE, ['precision' => $precision]);
    }

    public static function aggregate(): self
    {
        return new self(self::AGGREGATE);
    }

    public static function text(): self
    {
        return new self(self::TEXT);
    }

    /** @param class-string $contract a ContextContract subclass; relations go one level only */
    public static function relation(string $contract): self
    {
        if (!is_subclass_of($contract, ContextContract::class)) {
            throw new InvalidArgumentException(sprintf('Relation target %s is not a context contract.', $contract));
        }

        return new self(self::RELATION, ['contract' => $contract]);
    }

    /** Whether this form may carry a column of the given class. Secret is never allowed (constitution I). */
    public function allows(Ceiling $class): bool
    {
        return match ($this->form) {
            self::PLAIN, self::TEXT => in_array($class, [Ceiling::Public, Ceiling::Internal], true),
            self::PSEUDONYM => in_array($class, [Ceiling::Personal, Ceiling::Internal], true),
            default => $class !== Ceiling::Secret,
        };
    }
}
