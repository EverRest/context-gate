<?php

declare(strict_types=1);

namespace ContextGate\Pipeline;

use Closure;
use Normalizer as IntlNormalizer;
use RuntimeException;

/**
 * Stage 6 (R1, R9): NFKC on text fields, then the invisible and bidirectional control characters are removed.
 * Fails closed: a value that cannot be normalised refuses the call.
 */
final class Normalizer implements Stage
{
    private const INVISIBLE = '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}\x{E0000}-\x{E007F}]/u';

    /** @param list<string> $textFields */
    public function __construct(private readonly array $textFields)
    {
    }

    public function handle(Payload $payload, Closure $next): Payload
    {
        $payload->rows = array_map(function (array $row): array {
            foreach ($this->textFields as $field) {
                if (! isset($row[$field]) || ! is_string($row[$field])) {
                    continue;
                }

                $nfkc = IntlNormalizer::normalize($row[$field], IntlNormalizer::FORM_KC);

                if ($nfkc === false) {
                    throw new RuntimeException('text cannot be normalised');
                }

                $row[$field] = (string) preg_replace(self::INVISIBLE, '', $nfkc);
            }

            return $row;
        }, $payload->rows);

        return $next($payload);
    }
}
