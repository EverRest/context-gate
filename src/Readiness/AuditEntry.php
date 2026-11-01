<?php

declare(strict_types=1);

namespace ContextGate\Readiness;

/**
 * One tool in the gate:audit report (data-model AuditEntry). Names, classes, counts and hashes only, never a value.
 * A tool that is not enabled carries its name and nothing else.
 *
 * @internal May change without notice.
 */
final readonly class AuditEntry
{
    /**
     * @param array<string, int> $fieldClasses class => number of fields
     * @param array<string, string> $rules
     */
    private function __construct(
        public string $tool,
        public bool $enabled,
        public ?string $risk = null,
        public ?string $ceiling = null,
        public array $fieldClasses = [],
        public ?string $contract = null,
        public array $rules = [],
    ) {
    }

    public static function disabled(string $tool): self
    {
        return new self($tool, false);
    }

    /**
     * @param array<string, int> $fieldClasses
     * @param array<string, string> $rules
     */
    public static function enabled(string $tool, ?string $risk, string $ceiling, array $fieldClasses, ?string $contract, array $rules): self
    {
        return new self($tool, true, $risk, $ceiling, $fieldClasses, $contract, $rules);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        if (! $this->enabled) {
            return ['tool' => $this->tool, 'enabled' => false];
        }

        return [
            'tool' => $this->tool,
            'enabled' => true,
            'risk' => $this->risk,
            'ceiling' => $this->ceiling,
            'field_classes' => $this->fieldClasses,
            'contract' => $this->contract,
            'rules' => $this->rules,
        ];
    }
}
