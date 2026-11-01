<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Readiness;

use PHPUnit\Framework\TestCase;

/** T056, US4 scenario 3, FR-017: Composer, not the install script, refuses Laravel versions outside 12 and 13. */
class ComposerConstraintTest extends TestCase
{
    public function test_composer_allows_laravel_12_and_13_only(): void
    {
        $composer = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3).'/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertIsArray($composer);
        $this->assertIsArray($composer['require']);
        $this->assertSame('^12.0||^13.0', $composer['require']['laravel/framework'] ?? null);
    }
}
