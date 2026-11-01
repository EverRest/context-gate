<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Readiness\Checks;

use ContextGate\Classification\Catalog;
use ContextGate\Readiness\CheckResult;
use ContextGate\Readiness\CheckStatus;
use ContextGate\Readiness\Checks\SettingsCheck;
use ContextGate\Readiness\ReadinessContext;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\File;

/** spec 007 T019 (US1): a weaker profile in production fails and names the profile key. */
class SettingsCheckTest extends TestCase
{
    /** @param array<string, mixed> $config */
    private function checkWith(array $config, string $env): CheckResult
    {
        config(['app.env' => $env]);

        return (new SettingsCheck())->run(new ReadinessContext($config, new Catalog([]), []));
    }

    public function test_balanced_in_production_fails_and_names_the_profile(): void
    {
        $result = $this->checkWith(['profile' => 'balanced'], 'production');

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame('context-gate.profile', $result->subject);
        $this->assertStringContainsString('profile', $result->fix);
    }

    public function test_no_profile_in_production_passes(): void
    {
        $this->assertSame(CheckStatus::Pass, $this->checkWith([], 'production')->status);
    }

    public function test_no_profile_in_development_passes(): void
    {
        $this->assertSame(CheckStatus::Pass, $this->checkWith([], 'development')->status);
    }

    public function test_a_corrupt_index_fails_with_a_hint_naming_the_index_file_not_the_config_file(): void
    {
        $dir = sys_get_temp_dir().'/cg-settings-check-'.bin2hex(random_bytes(6));
        app()->useStoragePath($dir);
        File::ensureDirectoryExists($dir.'/app/context-gate/config');
        file_put_contents($dir.'/app/context-gate/config/index.json', '{not json');

        try {
            $result = $this->checkWith([], 'development');
        } finally {
            File::deleteDirectory($dir);
        }

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertStringContainsString('storage/app/context-gate/config/index.json', $result->fix);
        $this->assertStringNotContainsString('config/context-gate.php', $result->fix);
    }

    public function test_a_missing_active_version_file_fails_with_a_hint_to_import_or_roll_back(): void
    {
        $dir = sys_get_temp_dir().'/cg-settings-check-'.bin2hex(random_bytes(6));
        app()->useStoragePath($dir);
        File::ensureDirectoryExists($dir.'/app/context-gate/config');
        file_put_contents($dir.'/app/context-gate/config/index.json', json_encode([
            'active' => str_repeat('a', 64),
            'versions' => [['hash' => str_repeat('a', 64), 'applied_at' => '2026-11-01T00:00:00Z', 'actor' => 'cli', 'previous' => null]],
        ], JSON_THROW_ON_ERROR));

        try {
            $result = $this->checkWith([], 'development');
        } finally {
            File::deleteDirectory($dir);
        }

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertStringContainsString('import or roll back to a valid version', $result->fix);
        $this->assertStringNotContainsString('config/context-gate.php', $result->fix);
    }
}
