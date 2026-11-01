<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

/** US3 scenario 1, FR-015: every gate command the docs mention is registered in the application. */
class DocsCommandsTest extends TestCase
{
    /** The commands an operator runs; the docs must cover each of them. */
    private const REQUIRED = ['gate:install', 'gate:check', 'gate:token', 'gate:audit', 'gate:fingerprint'];

    public function test_every_gate_command_mentioned_in_docs_is_registered(): void
    {
        $registered = array_keys(Artisan::all());
        $unknown = array_values(array_diff($this->mentioned(), $registered));

        $this->assertSame([], $unknown, 'Documented commands that are not registered');
    }

    public function test_the_docs_mention_every_operator_command(): void
    {
        $missing = array_values(array_diff(self::REQUIRED, $this->mentioned()));

        $this->assertSame([], $missing, 'Operator commands the docs do not mention');
    }

    /** @return list<string> */
    private function mentioned(): array
    {
        $names = [];

        foreach (glob(dirname(__DIR__, 2).'/docs/*.md') ?: [] as $file) {
            preg_match_all('/php artisan (gate:[a-z-]+)/', (string) file_get_contents($file), $matches);
            array_push($names, ...$matches[1]);
        }

        return array_values(array_unique($names));
    }
}
