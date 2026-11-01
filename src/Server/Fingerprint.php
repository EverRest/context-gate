<?php

declare(strict_types=1);

namespace ContextGate\Server;

/** Pin over exactly what tools/list shows the client: name, description, input schema and annotations. */
final class Fingerprint
{
    public static function of(Tool $tool): string
    {
        return self::digest([
            'name' => $tool->name(),
            'description' => $tool->description(),
            'schema' => $tool->inputSchema(),
            'annotations' => $tool->annotations(),
        ]);
    }

    /** sha256 of the canonical JSON of a value: keys sorted, lists in order. Shared by every fingerprint. */
    public static function digest(mixed $value): string
    {
        return hash('sha256', json_encode(self::sorted($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** Sorts associative keys recursively; list order is meaningful and kept. */
    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::sorted(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
