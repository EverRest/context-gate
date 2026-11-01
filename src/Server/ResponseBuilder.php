<?php

declare(strict_types=1);

namespace ContextGate\Server;

use ContextGate\Pipeline\Payload;
use ContextGate\Settings\ActiveSettings;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/** The tools/call responses and the receipt line each one writes. Text never carries a value (FR-024). */
final class ResponseBuilder
{
    public static function receipt(Caller $caller, ?string $tool, ?ReasonCode $reason = null, ?Payload $payload = null, ?string $refusalStep = null): string
    {
        $rules = app()->bound('context-gate.rules') ? app('context-gate.rules') : [];
        $preview = app()->bound(Preview::class) ? app(Preview::class) : null;
        $source = $preview?->source;
        $preview?->record($reason, $payload);

        /** @var array<string, string> $rules */
        if ($payload === null) {
            return Receipt::write($caller->id, $tool, $reason, $rules, refusalStep: $refusalStep, source: $source);
        }

        return Receipt::write(
            $caller->id,
            $tool,
            $reason,
            $rules,
            $payload->dropped,
            in_array('budget.call', $payload->flags, true),
            array_values(array_diff($payload->flags, ['budget.call'])),
            $refusalStep,
            $source,
        );
    }

    /** R8: null when the settings document is valid; otherwise the refusal, with its receipt written as config.invalid. */
    public static function configRefusal(JsonRpcRequest $request, Caller $caller, string $name): ?JsonRpcResponse
    {
        if (app(ActiveSettings::class)->valid()) {
            return null;
        }

        self::receipt($caller, $name, ReasonCode::ConfigInvalid);

        return self::refused($request);
    }

    public static function refused(JsonRpcRequest $request, ?string $text = null): JsonRpcResponse
    {
        return JsonRpcResponse::result($request->id, [
            'content' => [['type' => 'text', 'text' => $text ?? 'Request refused.']],
            'isError' => true,
        ]);
    }

    public static function failed(JsonRpcRequest $request, string $reference): JsonRpcResponse
    {
        return JsonRpcResponse::result($request->id, [
            'content' => [['type' => 'text', 'text' => "Request failed. Reference: {$reference}"]],
            'isError' => true,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $flags
     */
    public static function result(JsonRpcRequest $request, array $rows, bool $isList, bool $truncated, array $flags): JsonRpcResponse
    {
        return JsonRpcResponse::result($request->id, [
            'content' => [['type' => 'text', 'text' => (string) json_encode($isList ? $rows : ($rows[0] ?? []), JSON_THROW_ON_ERROR)]],
            'isError' => false,
            'structuredContent' => ['rows' => count($rows), 'truncated' => $truncated, 'flags' => $flags],
        ]);
    }
}
