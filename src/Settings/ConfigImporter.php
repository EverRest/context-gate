<?php

declare(strict_types=1);

namespace ContextGate\Settings;

use RuntimeException;

/**
 * spec 007 US6, FR-013, SC-004: parses a settings file, validates the whole document with the live validator and,
 * only when it is clean, stores it as the active version and audits it. Any violation changes nothing.
 *
 * @internal May change without notice.
 */
final class ConfigImporter
{
    /**
     * @return list<array{key: string, message: string}> every violation; empty when the file was applied
     */
    public static function import(string $path, string $env, string $actor): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return [['key' => 'file', 'message' => 'cannot be read']];
        }

        $document = json_decode((string) file_get_contents($path), true);

        if (! is_array($document)) {
            return [['key' => 'file', 'message' => 'is not a JSON object']];
        }

        $violations = SettingsValidator::violations($document, $env);

        if (! empty($document['canaries']['values'] ?? [])) {
            $violations[] = ['key' => 'canaries.values', 'message' => 'canary values are set from the environment and are not imported'];
        }

        if ($violations !== []) {
            return $violations;
        }

        $store = ConfigVersionStore::fromApp();

        try {
            $active = $store->active();
            $before = $active === null ? [] : ($store->find($active) ?? []);
            $store->save($document, $actor);
        } catch (RuntimeException $error) {
            return [['key' => 'versions/index.json', 'message' => $error->getMessage()]];
        }

        ConfigAudit::record('config.applied', $actor, $before, $document);

        return [];
    }
}
