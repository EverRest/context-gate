<?php

declare(strict_types=1);

namespace ContextGate\Pipeline;

use Closure;
use Normalizer as IntlNormalizer;
use RuntimeException;

/**
 * Stage 6 (R1, R9): NFKC on the string values of the listed fields, nested rows included, then the invisible and
 * bidirectional control characters are removed. The firewall lists every projected field, so a canary split by
 * invisible characters is seen whatever its form. Fails closed: a value that cannot be normalised refuses the call.
 */
final class Normalizer implements Stage
{
    private const INVISIBLE = '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}\x{E0000}-\x{E007F}]/u';

    /** @param list<string> $fields */
    public function __construct(private readonly array $fields)
    {
    }

    public function handle(Payload $payload, Closure $next): Payload
    {
        $payload->rows = array_map(function (array $row): array {
            foreach ($this->fields as $field) {
                if (isset($row[$field])) {
                    $row[$field] = self::value($row[$field]);
                }
            }

            return $row;
        }, $payload->rows);

        return $next($payload);
    }

    /** A string is normalised; an array (a nested related row) is normalised value by value. */
    private static function value(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::value(...), $value);
        }

        return is_string($value) ? self::text($value) : $value;
    }

    /** NFKC, then the invisible characters removed. Throws when the value cannot be normalised. */
    public static function text(string $text): string
    {
        $nfkc = IntlNormalizer::normalize($text, IntlNormalizer::FORM_KC);

        if ($nfkc === false) {
            throw new RuntimeException('text cannot be normalised');
        }

        return (string) preg_replace(self::INVISIBLE, '', $nfkc);
    }

    /**
     * Every key and string value of a tree, normalised, for the canary check. Keys are kept as pairs, so two keys
     * that normalise to the same text cannot overwrite each other and hide a value.
     *
     * @param array<array-key, mixed> $value
     * @return array<string, mixed>
     */
    public static function tree(array $value): array
    {
        $pairs = [];

        foreach ($value as $key => $item) {
            $pairs[] = [
                is_string($key) ? self::text($key) : $key,
                is_array($item) ? self::tree($item) : (is_string($item) ? self::text($item) : $item),
            ];
        }

        return ['pairs' => $pairs];
    }
}
