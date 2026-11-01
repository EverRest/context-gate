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

    public function test_release_creation_runs_only_when_publish_is_true(): void
    {
        $creation = array_values(array_filter(
            $this->steps(),
            static fn (array $step): bool => str_contains(self::text($step), 'gh release create')
        ));

        $this->assertCount(1, $creation, 'Expected exactly one release-creation step');
        $this->assertSame(self::CONDITION, $creation[0]['if'] ?? null);
    }

    public function test_no_step_publishes_without_the_publish_condition(): void
    {
        $this->assertSame(['workflow_dispatch'], array_keys($this->workflow()['on'] ?? []), 'Only workflow_dispatch may trigger the release');

        foreach ($this->steps() as $step) {
            if (preg_match(self::PUBLISHING, self::text($step)) === 1) {
                $this->assertSame(self::CONDITION, $step['if'] ?? null, 'Publishing step without the publish condition: '.($step['name'] ?? '?'));
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
