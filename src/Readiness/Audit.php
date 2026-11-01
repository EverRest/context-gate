<?php

declare(strict_types=1);

namespace ContextGate\Readiness;

use ContextGate\Classification\Catalog;
use ContextGate\Contract\ContextContract;
use ContextGate\Contract\ContractValidator;
use ContextGate\Server\ToolRegistry;

/** FR-001, FR-002 (research R3): one AuditEntry per configured tool, from its config, its contract and the rule hashes. */
final readonly class Audit
{
    /**
     * @param array<string, array<string, mixed>> $tools config tool entries by name
     * @param array<string, string> $rules
     */
    public function __construct(
        private array $tools,
        private string $ceiling,
        private Catalog $catalog,
        private array $rules,
    ) {
    }

    /** @return list<AuditEntry> */
    public function entries(): array
    {
        $entries = [];

        foreach ($this->tools as $name => $entry) {
            $entries[] = ($entry['enabled'] ?? false) === true
                ? $this->enabled((string) $name, $entry)
                : AuditEntry::disabled((string) $name);
        }

        return $entries;
    }

    /** @param array<string, mixed> $entry */
    private function enabled(string $name, array $entry): AuditEntry
    {
        $risk = $entry['risk'] ?? null;
        $contractClass = ToolRegistry::contractClassOf($entry);

        return AuditEntry::enabled(
            $name,
            is_string($risk) && in_array($risk, ['low', 'medium', 'high'], true) ? $risk : null,
            $this->ceiling,
            $contractClass === null ? [] : $this->fieldClasses($contractClass),
            $contractClass,
            $this->rules,
        );
    }

    /**
     * @param class-string<ContextContract> $contractClass
     * @return array<string, int>
     */
    private function fieldClasses(string $contractClass): array
    {
        $contract = app($contractClass);
        $table = (new ContractValidator())->tableOf($contract);
        $counts = [];

        foreach (array_keys($contract->fields()) as $column) {
            $class = $this->catalog->classOf($table, (string) $column);

            if ($class !== null) {
                $counts[$class->value] = ($counts[$class->value] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
