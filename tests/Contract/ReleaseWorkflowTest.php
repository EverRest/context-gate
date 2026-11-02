<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/** A release is created automatically when a version tag is pushed, and only after the CI gate passes. */
class ReleaseWorkflowTest extends TestCase
{
    /** Steps that publish: a GitHub release, a tag, a push or a package registry. */
    private const PUBLISHING = '/gh release|action-gh-release|softprops|git push|git tag|npm publish|composer publish/';

    /** The guards, in the order the release job must run them. */
    private const GUARDS = ['pinned', 'annotated', 'tip', 'version', 'changelog'];

    public function test_release_is_triggered_only_by_a_version_tag_push(): void
    {
        $on = self::map($this->workflow()['on'] ?? []);

        $this->assertSame(['push'], array_keys($on), 'Only a tag push may trigger the release');
        $this->assertSame(['v*'], self::map($on['push'] ?? [])['tags'] ?? null);
    }

    public function test_there_is_no_manual_dispatch_or_publish_input(): void
    {
        $this->assertArrayNotHasKey('workflow_dispatch', self::map($this->workflow()['on'] ?? []));
        $this->assertStringNotContainsString('inputs.', (string) json_encode($this->workflow(), JSON_UNESCAPED_SLASHES));
    }

    public function test_gate_job_runs_the_ci_workflow(): void
    {
        $gate = self::map($this->jobs()['gate'] ?? []);

        $this->assertSame('./.github/workflows/ci.yml', $gate['uses'] ?? null);
    }

    public function test_release_job_waits_for_the_gate_and_runs_on_ubuntu(): void
    {
        $release = self::map($this->jobs()['release'] ?? []);

        $this->assertSame('gate', $release['needs'] ?? null);
        $this->assertSame('ubuntu-latest', $release['runs-on'] ?? null);
    }

    public function test_release_creation_lives_only_in_the_release_job_with_verify_tag(): void
    {
        $jobs = $this->jobs();

        $this->assertStringContainsString('gh release create', self::text(self::map($jobs['release'] ?? [])));
        $this->assertStringContainsString('--verify-tag', self::text(self::map($jobs['release'] ?? [])));
        foreach ($jobs as $name => $job) {
            if ($name !== 'release') {
                $this->assertStringNotContainsString('gh release create', self::text(self::map($job)), "Job {$name} creates a release");
            }
        }
    }

    public function test_write_permissions_sit_only_on_the_release_job(): void
    {
        $jobs = $this->jobs();
        $release = self::map(self::map($jobs['release'] ?? [])['permissions'] ?? []);

        $this->assertSame('write', $release['contents'] ?? null);
        $this->assertSame('write', $release['attestations'] ?? null);
        $this->assertSame('write', $release['id-token'] ?? null);
        foreach ($jobs as $name => $job) {
            if ($name !== 'release') {
                $this->assertNotSame('write', self::map(self::map($job)['permissions'] ?? [])['contents'] ?? null, "Job {$name} can write");
            }
        }
        $this->assertSame('read', self::map($this->workflow()['permissions'] ?? [])['contents'] ?? null);
    }

    public function test_no_step_publishes_outside_the_release_job(): void
    {
        foreach ($this->jobs() as $name => $job) {
            if ($name === 'release') {
                continue;
            }
            foreach (self::map(self::map($job)['steps'] ?? []) as $step) {
                $this->assertDoesNotMatchRegularExpression(self::PUBLISHING, self::text(self::map($step)), "Job {$name} publishes");
            }
        }
    }

    public function test_each_guard_runs_before_the_archive_is_built(): void
    {
        $steps = array_map(static fn (array $step): string => self::text($step), $this->releaseSteps());
        $archive = $this->indexOf($steps, 'git archive');

        foreach (self::GUARDS as $guard) {
            $this->assertLessThan($archive, $this->indexOf($steps, "check-release.sh {$guard}"), "Guard {$guard} runs after the archive");
        }
    }

    public function test_guards_run_in_order(): void
    {
        $steps = array_map(static fn (array $step): string => self::text($step), $this->releaseSteps());
        $indexes = array_map(fn (string $guard): int => $this->indexOf($steps, "check-release.sh {$guard}"), self::GUARDS);

        $this->assertSame($indexes, array_values(array_unique($indexes)), 'each guard is its own step');
        $sorted = $indexes;
        sort($sorted);
        $this->assertSame($sorted, $indexes, 'guards must run in the order pinned, annotated, tip, version, changelog');
    }

    public function test_archive_is_built_from_the_run_commit_and_attested(): void
    {
        $steps = array_map(static fn (array $step): string => self::text($step), $this->releaseSteps());

        $this->assertStringContainsString('$GITHUB_SHA', $steps[$this->indexOf($steps, 'git archive')]);
        $this->assertStringContainsString('actions/attest-build-provenance@4d101475d8b20a2381f78447822ac1eab6504dd8', $steps[$this->indexOf($steps, 'attest-build-provenance')]);
    }

    /** @return array<mixed> */
    /** Spec 008 FR-004 (T041): the Redis suite runs in its own CI job; the test job never opts into it. */
    public function test_ci_runs_the_redis_concurrency_job_with_a_redis_service(): void
    {
        $ci = $this->ci();
        $job = self::map(self::map($ci['jobs'] ?? [])['concurrency-redis'] ?? []);

        $this->assertNotSame([], $job, 'ci.yml has a concurrency-redis job');
        $this->assertArrayHasKey('redis', self::map($job['services'] ?? []));
        $this->assertStringContainsString('CONTEXT_GATE_CONCURRENCY: \'1\'', (string) file_get_contents($this->ciFile()));
        $this->assertStringContainsString('CONTEXT_GATE_STORE=redis', (string) json_encode($job, JSON_UNESCAPED_SLASHES));
    }

    /** Owner decision (spec 008 Amendments): the nightly schedule runs the Redis concurrency job only. */
    public function test_only_the_concurrency_job_runs_on_the_nightly_schedule(): void
    {
        foreach (self::map($this->ci()['jobs'] ?? []) as $name => $job) {
            $condition = (string) (self::map($job)['if'] ?? '');

            if ($name === 'concurrency-redis') {
                $this->assertStringNotContainsString("!= 'schedule'", $condition, 'the nightly job must run');
                continue;
            }

            $this->assertStringContainsString("github.event_name != 'schedule'", $condition, "{$name} must not run nightly");
        }
    }

    /** Owner decision (spec 008 Amendments): the Redis job stays non-blocking until its 100-repeat run passes. */
    public function test_the_redis_job_is_non_blocking_on_the_nightly_run(): void
    {
        $job = self::map(self::map($this->ci()['jobs'] ?? [])['concurrency-redis'] ?? []);

        $this->assertStringContainsString("github.event_name == 'schedule'", (string) ($job['continue-on-error'] ?? ''));
    }

    public function test_the_default_test_job_never_sets_the_concurrency_opt_in(): void
    {
        $test = self::map(self::map($this->ci()['jobs'] ?? [])['test'] ?? []);

        $this->assertStringNotContainsString('CONTEXT_GATE_CONCURRENCY', (string) json_encode($test, JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed> */
    private function ci(): array
    {
        /** @var array<string, mixed> $ci */
        $ci = Yaml::parseFile($this->ciFile());

        return $ci;
    }

    private function ciFile(): string
    {
        $file = dirname(__DIR__, 2).'/.github/workflows/ci.yml';
        $this->assertFileExists($file);

        return $file;
    }

    /** @return array<string, mixed> */
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
    private function releaseSteps(): array
    {
        return array_values(array_map(static fn (mixed $step): array => self::map($step), self::map(self::map($this->jobs()['release'] ?? [])['steps'] ?? [])));
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
