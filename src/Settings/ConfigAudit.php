<?php

declare(strict_types=1);

namespace ContextGate\Settings;

use Illuminate\Support\Facades\Log;

/**
 * One audit line per applied or rolled-back version on the context-gate channel.
 * The line holds key names and document hashes. A canary change is recorded as the key `canaries.values` only.
 *
 * @internal May change without notice.
 */
final class ConfigAudit
{
    /**
     * @param array<string, mixed> $old the document before the change
     * @param array<string, mixed> $new the document after the change
     */
    public static function record(string $event, string $actor, array $old, array $new): void
    {
        Log::channel(config('context-gate.channel'))->info($event, [
            'event' => $event,
            'actor' => $actor,
            'at' => gmdate('Y-m-d\TH:i:s\Z'),
            'changed_keys' => self::changedKeys($old, $new),
            'old_hash' => ConfigVersionStore::hashOf($old),
            'new_hash' => ConfigVersionStore::hashOf($new),
        ]);
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     * @return list<string>
     */
    private static function changedKeys(array $old, array $new): array
    {
        $before = self::flatten($old);
        $after = self::flatten($new);
        $changed = [];

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $path) {
            if (($before[$path] ?? null) !== ($after[$path] ?? null)) {
                $changed[] = str_starts_with($path, 'canaries.') ? 'canaries.values' : $path;
            }
        }

        return array_values(array_unique($changed));
    }

    /**
     * Dotted leaf paths to their values. Lists and empty arrays are leaves.
     *
     * @param array<array-key, mixed> $node
     * @return array<string, mixed>
     */
    private static function flatten(array $node, string $prefix = ''): array
    {
        $leaves = [];

        foreach ($node as $name => $value) {
            $path = $prefix === '' ? (string) $name : "{$prefix}.{$name}";

            if (is_array($value) && ! array_is_list($value)) {
                $leaves += self::flatten($value, $path);
            } else {
                $leaves[$path] = $value;
            }
        }

        return $leaves;
    }
}
