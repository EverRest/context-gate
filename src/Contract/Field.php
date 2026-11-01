<?php

declare(strict_types=1);

namespace ContextGate\Contract;

use ContextGate\Classification\Ceiling;
use InvalidArgumentException;

/**
 * The form in which one catalog column reaches the caller. Factories take no classification:
 * the catalog decides which forms are allowed (see allows()).
 *
 * @api Supported extension surface: covered by the compatibility policy in the README.
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

    /** The scope is how long the pseudonym holds: session (default), day or week. Nothing longer exists. */
    public static function pseudonym(string $prefix, string $scope = 'session'): self
    {
        if (! in_array($scope, ['session', 'day', 'week'], true)) {
            throw new InvalidArgumentException(sprintf('Pseudonym scope [%s] is not allowed.', $scope));
        }

        return new self(self::PSEUDONYM, ['prefix' => $prefix, 'scope' => $scope]);
    }

    /** Most breaks a bucket may have: more would be a fine grid that returns the value itself. */
    public const MAX_BREAKS = 20;

    /** With 2+ breaks, adjacent breaks must differ by at least this share of the span first..last (5%). */
    public const MIN_GAP = 0.05;

    /** ...and by at least this absolute amount: a width-1 bucket of whole numbers is the raw value. */
    public const MIN_WIDTH = 2;

    /**
     * Breaks must be 1 to MAX_BREAKS numbers, strictly ascending, each adjacent gap at least
     * max(MIN_WIDTH, MIN_GAP * span) (a single break has no gap), so a bucket never narrows down to one value.
     *
     * @param array<mixed> $breaks
     */
    public static function bucket(array $breaks): self
    {
        if (count($breaks) < 1 || count($breaks) > self::MAX_BREAKS) {
            throw new InvalidArgumentException(sprintf('Bucket needs 1 to %d breaks.', self::MAX_BREAKS));
        }

        $list = [];
        $last = null;

        foreach ($breaks as $break) {
            if (! is_int($break) && ! is_float($break)) {
                throw new InvalidArgumentException('Bucket breaks must be numbers.');
            }

            if ($last !== null && $break <= $last) {
                throw new InvalidArgumentException('Bucket breaks must be strictly ascending.');
            }

            $list[] = $last = $break;
        }

        $min = max(self::MIN_WIDTH, self::MIN_GAP * (end($list) - $list[0]));

        for ($i = 1; $i < count($list); $i++) {
            if ($list[$i] - $list[$i - 1] < $min) {
                throw new InvalidArgumentException(sprintf('Bucket breaks must be at least %s apart (max of %d and %d%% of the span).', $min, self::MIN_WIDTH, self::MIN_GAP * 100));
            }
        }

        return new self(self::BUCKET, ['breaks' => $list]);
    }

    /** Only year or month: a full date would name the record. */
    public static function date(string $precision): self
    {
        if (! in_array($precision, ['year', 'month'], true)) {
            throw new InvalidArgumentException(sprintf('Date precision [%s] is not allowed; use year or month.', $precision));
        }

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
