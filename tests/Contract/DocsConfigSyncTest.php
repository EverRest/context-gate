<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use PHPUnit\Framework\TestCase;

/** US3 scenario 2, SC-004, FR-015: docs/configuration.md and config/context-gate.php list the same keys. */
class DocsConfigSyncTest extends TestCase
{
    public function test_every_config_key_is_documented(): void
    {
        $missing = array_values(array_diff($this->configKeys(), $this->documentedKeys()));

        $this->assertSame([], $missing, 'Config keys missing from docs/configuration.md');
    }

    public function test_every_documented_key_exists_in_the_config_file(): void
    {
        $unknown = array_values(array_diff($this->documentedKeys(), $this->configKeys()));

        $this->assertSame([], $unknown, 'Keys in docs/configuration.md that the config file does not have');
    }

    /** @return list<string> dotted paths of the shipped config; lists and empty arrays are leaves */
    private function configKeys(): array
    {
        $config = require dirname(__DIR__, 2).'/config/context-gate.php';
        $this->assertIsArray($config);

        return $this->paths($config);
    }

    /**
     * @param array<array-key, mixed> $config
     * @return list<string>
     */
    private function paths(array $config, string $prefix = ''): array
    {
        $paths = [];

        foreach ($config as $key => $value) {
            $path = $prefix.$key;

            // classification is an operator-owned catalog (table => column => class): documented as one key.
            if (is_array($value) && $value !== [] && ! array_is_list($value) && $path !== 'classification') {
                array_push($paths, ...$this->paths($value, $path.'.'));
            } else {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /** @return list<string> keys named by a level-two heading, e.g. "## `firewall.limits.per_call_bytes`" */
    private function documentedKeys(): array
    {
        $file = dirname(__DIR__, 2).'/docs/configuration.md';
        $this->assertFileExists($file);

        preg_match_all('/^## `([a-z_]+(?:\.[a-z_]+)*)`\s*$/m', (string) file_get_contents($file), $matches);

        return $matches[1];
    }
}
