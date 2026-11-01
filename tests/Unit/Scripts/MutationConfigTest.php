<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3).'/scripts/check-mutation-config.php';

/**
 * Spec 011 US1, FR-003 to FR-006: the mutation configuration cannot lower the minimum score, exclude a mutant, or
 * scope the run away from the security paths. Every case runs in a scratch copy of a valid tree.
 */
class MutationConfigTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/context-gate-mutation-'.bin2hex(random_bytes(6));
        foreach (MUTATION_REQUIRED_SCOPE as $path) {
            if (str_ends_with($path, '/')) {
                mkdir($this->dir.'/'.$path, 0777, true);
            } else {
                $this->write($path, "<?php\n\nfinal class Stub {}\n");
            }
        }
        $this->write('src/Pipeline/Stub.php', "<?php\n\nfinal class Stub {}\n");
        $this->write('infection.json5', "{\n  \"source\": { \"directories\": [\"src\"] },\n  \"minMsi\": 90\n}\n");
        $this->write('scripts/mutation-scope.php', $this->scopeFile(MUTATION_REQUIRED_SCOPE));
        $this->write('composer.json', "{\n  \"scripts\": { \"mutation\": \"php scripts/mutation.php\" }\n}\n");
        $this->write('.github/workflows/ci.yml', "jobs:\n  mutation:\n    steps:\n      - run: composer mutation\n");
    }

    protected function tearDown(): void
    {
        $this->remove($this->dir);
        parent::tearDown();
    }

    public function test_valid_tree_has_no_violations(): void
    {
        $this->assertSame([], mutation_config_violations($this->dir));
    }

    public function test_real_repository_has_no_violations(): void
    {
        $this->assertSame([], mutation_config_violations(dirname(__DIR__, 3)));
    }

    public function test_ignore_key_in_the_config_is_a_violation(): void
    {
        $this->write('infection.json5', "{\n  \"minMsi\": 90,\n  \"mutators\": { \"global-ignore\": [\"Foo\"] }\n}\n");

        $this->assertViolation('infection.json5', 'ignore');
    }

    public function test_ignore_source_code_by_regex_is_a_violation(): void
    {
        $this->write('infection.json5', "{\n  \"minMsi\": 90,\n  \"mutators\": { \"global-ignoreSourceCodeByRegex\": [\".*\"] }\n}\n");

        $this->assertViolation('infection.json5', 'ignoreSourceCodeByRegex');
    }

    public function test_infection_ignore_annotation_in_src_names_file_and_line(): void
    {
        $this->write('src/Pipeline/Stub.php', "<?php\n\nfinal class Stub\n{\n    // @infection-ignore-all\n    public function x(): void {}\n}\n");

        $this->assertSame(['src/Pipeline/Stub.php:5: @infection-ignore is not allowed'], mutation_config_violations($this->dir));
    }

    public function test_min_msi_below_the_floor_is_a_violation(): void
    {
        $this->write('infection.json5', "{\n  \"minMsi\": 89\n}\n");

        $this->assertViolation('infection.json5', 'minMsi must be at least 90');
    }

    public function test_missing_scope_path_is_a_violation(): void
    {
        $this->write('scripts/mutation-scope.php', $this->scopeFile(array_merge(MUTATION_REQUIRED_SCOPE, ['src/Gone/'])));

        $this->assertViolation('scripts/mutation-scope.php', 'src/Gone/ does not exist');
    }

    public function test_scope_without_one_security_group_is_a_violation(): void
    {
        $scope = array_values(array_diff(MUTATION_REQUIRED_SCOPE, ['src/Privacy/']));
        $this->write('scripts/mutation-scope.php', $this->scopeFile($scope));

        $this->assertViolation('scripts/mutation-scope.php', 'src/Privacy/ is missing');
    }

    public function test_min_msi_flag_in_composer_is_a_violation(): void
    {
        $this->write('composer.json', "{\n  \"scripts\": { \"mutation\": \"infection --min-msi=50\" }\n}\n");

        $this->assertViolation('composer.json', '--min-msi');
    }

    public function test_threshold_flag_in_the_workflow_is_a_violation(): void
    {
        $this->write('.github/workflows/ci.yml', "jobs:\n  mutation:\n    steps:\n      - run: composer mutation -- --ignore-msi-with-no-mutations\n");

        $this->assertViolation('.github/workflows/ci.yml', '--ignore-msi-with-no-mutations');
    }

    /** @param list<string> $scope */
    private function scopeFile(array $scope): string
    {
        $lines = array_map(static fn (string $p): string => "    '".$p."',", $scope);

        return "<?php\n\nreturn [\n".implode("\n", $lines)."\n];\n";
    }

    private function assertViolation(string $file, string $fragment): void
    {
        $violations = mutation_config_violations($this->dir);
        $this->assertNotSame([], $violations);
        $this->assertStringContainsString($file, implode("\n", $violations));
        $this->assertStringContainsString($fragment, implode("\n", $violations));
    }

    private function write(string $rel, string $content): void
    {
        $path = $this->dir.'/'.$rel;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
    }

    private function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
