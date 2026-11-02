<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Settings;

use ContextGate\Settings\BuiltinClassification;
use ContextGate\Settings\ClassificationMerge;
use ContextGate\Tests\TestCase;

/** spec 009 FR-008..FR-011, contracts/classification-config.md: the built-in tables merged with the host's map. */
class ClassificationMergeTest extends TestCase
{
    public function test_an_empty_host_map_gives_the_six_built_in_tables(): void
    {
        $effective = ClassificationMerge::effective([]);

        $this->assertSame(BuiltinClassification::TABLES, $effective);
        $this->assertSame(
            ['source_routes', 'source_config_keys', 'source_schema_items', 'source_docs', 'source_runbook_rows', 'source_openapi_endpoints'],
            array_keys($effective),
        );
        $this->assertSame([], ClassificationMerge::violations([]));
    }

    public function test_false_hides_a_built_in_table(): void
    {
        $effective = ClassificationMerge::effective(['source_docs' => false]);

        $this->assertArrayNotHasKey('source_docs', $effective);
        $this->assertArrayHasKey('source_routes', $effective);
        $this->assertSame([], ClassificationMerge::violations(['source_docs' => false]));
    }

    public function test_false_on_a_column_hides_that_column_only(): void
    {
        $effective = ClassificationMerge::effective(['source_routes' => ['middleware' => false]]);

        $this->assertArrayNotHasKey('middleware', $effective['source_routes']);
        $this->assertSame('internal', $effective['source_routes']['uri']);
        $this->assertSame([], ClassificationMerge::violations(['source_routes' => ['middleware' => false]]));
    }

    public function test_repeating_a_built_in_table_exactly_is_no_change(): void
    {
        $this->assertSame(ClassificationMerge::effective([]), ClassificationMerge::effective(['source_routes' => BuiltinClassification::TABLES['source_routes']]));
        $this->assertSame([], ClassificationMerge::violations(['source_routes' => BuiltinClassification::TABLES['source_routes']]));
    }

    public function test_host_tables_are_added_unchanged(): void
    {
        $effective = ClassificationMerge::effective(['orders' => ['id' => 'public', 'email' => 'personal']]);

        $this->assertSame(['id' => 'public', 'email' => 'personal'], $effective['orders']);
        $this->assertSame([], ClassificationMerge::violations(['orders' => ['id' => 'public', 'email' => 'personal']]));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> the host map and the one violation key it must give */
    public static function refused(): iterable
    {
        yield 'another class on a built-in column' => [['source_routes' => ['uri' => 'public']], 'classification.source_routes.uri'];
        yield 'a new column on a built-in table' => [['source_routes' => ['extra' => 'internal']], 'classification.source_routes.extra'];
        yield 'an unknown source_ table' => [['source_custom' => ['a' => 'internal']], 'classification.source_custom'];
        yield 'a class outside the four' => [['orders' => ['id' => 'top-secret']], 'classification.orders.id'];
        yield 'a built-in table that is not a map or false' => [['source_docs' => 'internal'], 'classification.source_docs'];
        yield 'a host table that is not a map' => [['orders' => 'internal'], 'classification.orders'];
    }

    /** @param array<string, mixed> $host */
    #[\PHPUnit\Framework\Attributes\DataProvider('refused')]
    public function test_each_refused_entry_gives_one_violation_with_its_key(array $host, string $key): void
    {
        $violations = ClassificationMerge::violations($host);

        $this->assertCount(1, $violations);
        $this->assertSame($key, $violations[0]['key']);
    }

    public function test_a_classification_that_is_not_a_map_is_refused_under_its_own_key(): void
    {
        $violations = ClassificationMerge::violations('internal');

        $this->assertSame([['key' => 'classification', 'message' => 'must be a section of settings']], $violations);
    }

    public function test_the_source_prefix_message_names_the_reservation(): void
    {
        $violations = ClassificationMerge::violations(['source_custom' => ['a' => 'internal']]);

        $this->assertSame('the source_ prefix is reserved for the package', $violations[0]['message']);
    }
}
