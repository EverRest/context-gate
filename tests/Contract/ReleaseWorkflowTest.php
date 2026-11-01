<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/** FR-020: the release workflow is a dry run unless publish is set, and nothing publishes without that condition. */
class ReleaseWorkflowTest extends TestCase
{
    private const CONDITION = 'inputs.publish == true';

    /** Steps that publish: a GitHub release, a tag, a push or a package registry. */
    private const PUBLISHING = '/gh release|action-gh-release|softprops|git push|git tag|npm publish|composer publish/';

    public function test_publish_input_defaults_to_false(): void
    {
        $inputs = self::map(self::map(self::map($this->workflow()['on'] ?? [])['workflow_dispatch'] ?? [])['inputs'] ?? []);

        $this->assertArrayHasKey('publish', $inputs);
        $publish = self::map($inputs['publish']);
        $this->assertSame('boolean', $publish['type'] ?? null);
        $this->assertArrayHasKey('default', $publish);
        $this->assertFalse($publish['default']);
    }

    public function test_publish_job_runs_only_when_publish_is_true_after_the_build(): void
    {
        $publish = self::map($this->jobs()['publish'] ?? []);

        $this->assertSame(self::CONDITION, $publish['if'] ?? null);
        $this->assertSame('build', $publish['needs'] ?? null);
    }

    public function test_release_creation_lives_only_in_the_publish_job(): void
    {
        $jobs = $this->jobs();

        $this->assertStringContainsString('gh release create', self::text(self::map($jobs['publish'] ?? [])));
        foreach ($jobs as $name => $job) {
            if ($name !== 'publish') {
                $this->assertStringNotContainsString('gh release create', self::text(self::map($job)), "Job {$name} creates a release");
            }
        }
    }

    public function test_write_permission_sits_only_on_the_publish_job(): void
    {
        $jobs = $this->jobs();

        $this->assertSame('write', self::map(self::map($jobs['publish'] ?? [])['permissions'] ?? [])['contents'] ?? null);
        $this->assertSame('read', self::map(self::map($jobs['build'] ?? [])['permissions'] ?? [])['contents'] ?? null);
        foreach ($jobs as $name => $job) {
            if ($name !== 'publish') {
                $this->assertNotSame('write', self::map(self::map($job)['permissions'] ?? [])['contents'] ?? null, "Job {$name} can write");
            }
        }
    }

    public function test_no_step_publishes_outside_the_publish_job(): void
    {
        $this->assertSame(['workflow_dispatch'], array_keys($this->workflow()['on'] ?? []), 'Only workflow_dispatch may trigger the release');

        foreach ($this->jobs() as $name => $job) {
            if ($name === 'publish') {
                continue;
            }
            foreach (self::map(self::map($job)['steps'] ?? []) as $step) {
                $this->assertDoesNotMatchRegularExpression(self::PUBLISHING, self::text(self::map($step)), "Job {$name} publishes");
            }
        }
    }

    public function test_release_guards_run_before_the_archive_is_built(): void
    {
        $steps = array_map(static fn (array $step): string => self::text($step), $this->steps());
        $guard = $this->indexOf($steps, 'scripts/check-release.sh');
        $archive = $this->indexOf($steps, 'git archive');

        $this->assertLessThan($archive, $guard);
    }

    /** @return array<mixed> */
    private function workflow(): array
    {
        $file = dirname(__DIR__, 2).'/.github/workflows/release.yml';
        $this->assertFileExists($file);

        return self::map(Yaml::parseFile($file));
    }

    /** @return array<string, mixed> */
    private function jobs(): array
    {
        /** @var array<string, mixed> $jobs */
        $jobs = self::map($this->workflow()['jobs'] ?? []);

        return $jobs;
    }

    /** @return list<array<mixed>> */
    private function steps(): array
    {
        $steps = [];
        foreach (self::map($this->workflow()['jobs'] ?? []) as $job) {
            foreach (self::map(self::map($job)['steps'] ?? []) as $step) {
                $steps[] = self::map($step);
            }
        }

        return $steps;
    }

    /** @param list<string> $items */
    private function indexOf(array $items, string $needle): int
    {
        foreach ($items as $index => $item) {
            if (str_contains($item, $needle)) {
                return $index;
            }
        }
        $this->fail("No step contains: {$needle}");
    }

    /** @return array<mixed> */
    private static function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * The step as text, so a pattern can match its run, uses or name.
     *
     * @param array<mixed> $step
     */
    private static function text(array $step): string
    {
        return (string) json_encode($step, JSON_UNESCAPED_SLASHES);
    }
}
