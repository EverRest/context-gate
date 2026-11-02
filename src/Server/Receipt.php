<?php

declare(strict_types=1);

namespace ContextGate\Server;

use ContextGate\Settings\ActiveSettings;
use Illuminate\Support\Facades\Log;

/** @internal May change without notice. */
final class Receipt
{
    /**
     * Writes one metadata-only JSON line and returns the receipt id. Arguments and results never reach here.
     *
     * @param array<string, string>|null $rules rule hashes in force (tool, contract, classification)
     * @param list<array{field: string, reason: string}>|null $dropped fields removed by the projection
     * @param list<string>|null $flags detector names, never matched values
     * @param string|null $refusalStep the policy step that refused a source call; never the path
     * @param string|null $source where the call came from, set only for a preview
     * @param bool|null $simulated set only for a policy simulation; written only when true
     * @param array<string, int>|null $statusCounts runbook calls only: rows per status, never row text
     * @param string|null $role the caller's role name; 'none' when null
     */
    public static function write(
        string $caller,
        ?string $tool,
        ?ReasonCode $reason = null,
        ?array $rules = null,
        ?array $dropped = null,
        ?bool $truncated = null,
        ?array $flags = null,
        ?string $refusalStep = null,
        ?string $source = null,
        ?bool $simulated = null,
        ?array $statusCounts = null,
        ?string $role = null,
    ): string {
        $id = 'r_'.bin2hex(random_bytes(4));

        $record = [
            'id' => $id,
            'at' => gmdate('Y-m-d\TH:i:s\Z'),
            'caller' => $caller,
            'role' => $role ?? 'none',
            'tool' => $tool,
            'decision' => $reason?->decision() ?? 'allow',
            'reason' => $reason?->value,
            'rules' => (object) ($rules ?? []),
        ];

        // Optional fields appear only when the caller supplies them, so the base receipt format stays unchanged.
        if ($dropped !== null) {
            $record['dropped'] = $dropped;
        }

        if ($truncated !== null) {
            $record['truncated'] = $truncated;
        }

        if ($flags !== null) {
            $record['flags'] = $flags;
        }

        if ($refusalStep !== null) {
            $record['refusal_step'] = $refusalStep;
        }

        if ($source !== null) {
            $record['source'] = $source;
        }

        if ($simulated === true) {
            $record['simulated'] = true;
        }

        if ($statusCounts !== null) {
            $record['status_counts'] = (object) $statusCounts;
        }

        $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        Log::channel(app(ActiveSettings::class)->channel())->info($line);

        return $id;
    }
}
