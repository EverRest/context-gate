<?php

declare(strict_types=1);

namespace ContextGate\Server;

use ContextGate\Source\SourceFailure;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use Throwable;

/**
 * Runs the handler and decodes its output. The exception message stays out of the response and the receipt;
 * only the reference reaches the caller. A source failure keeps its own reason and step (never the path).
 *
 * @internal May change without notice.
 */
final class FetchStep
{
    public function __construct(private readonly FirewallRunner $firewall)
    {
    }

    /**
     * @param array<string, mixed> $arguments
     * @return JsonRpcResponse|array{0: list<array<string, mixed>>, 1: bool} the failure response, or the decoded rows and the list flag
     */
    public function run(JsonRpcRequest $request, Caller $caller, Tool $tool, array $arguments): JsonRpcResponse|array
    {
        $name = $tool->name();

        try {
            $this->firewall->stage('fetch');

            return $this->firewall->decode((string) $tool->handle($arguments)->content());
        } catch (SourceFailure $failure) {
            $reference = ResponseBuilder::receipt($caller, $name, $failure->reason, refusalStep: $failure->refusalStep);

            return ResponseBuilder::forReason($request, $failure->reason, $reference, $name);
        } catch (Throwable $e) {
            $reference = ResponseBuilder::receipt($caller, $name, ReasonCode::HandlerFailed);
            Failure::note($e, $reference);

            return ResponseBuilder::failed($request, $reference);
        }
    }
}
