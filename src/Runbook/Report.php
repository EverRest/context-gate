<?php

declare(strict_types=1);

namespace ContextGate\Runbook;

use ContextGate\Pipeline\Caps;

/**
 * The rows of one runbook run, capped at the report limit (spec 018 FR-016). A row is kept whole or dropped whole and
 * counted; no text is cut. `bytes` is the JSON size of the rows list and never exceeds the cap.
 *
 * @internal May change without notice.
 */
final readonly class Report
{
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    /** @param list<array{step: string, status: string, text: string}> $rows */
    public function __construct(
        public array $rows,
        public int $droppedRows,
        public int $bytes,
    ) {
    }

    /** @param list<array{step: string, result: StepResult}> $results one per step, in declared order */
    public static function fromResults(array $results, int $limit = Caps::RUNBOOK_REPORT_BYTES): self
    {
        $rows = [];
        $dropped = 0;
        $bytes = 2;

        foreach ($results as $item) {
            $row = [
                'step' => $item['step'],
                'status' => $item['result']->status->value,
                'text' => $item['result']->text,
            ];

            $size = strlen((string) json_encode($row, self::JSON_FLAGS));
            $separator = $rows === [] ? 0 : 1;

            if ($bytes + $separator + $size > $limit) {
                $dropped++;

                continue;
            }

            $rows[] = $row;
            $bytes += $separator + $size;
        }

        return new self($rows, $dropped, $bytes);
    }
}
