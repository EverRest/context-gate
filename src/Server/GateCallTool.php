<?php

declare(strict_types=1);

namespace ContextGate\Server;

use ContextGate\Pipeline\Budget;
use ContextGate\Support\GateCache;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use Throwable;

/** @internal May change without notice. */
final class GateCallTool implements Method
{
    /** ponytail: the session is the stdio process and its single caller; the session TTL is one hour, no config. */
    private const SESSION_TTL = 3600;

    /** The tool name once it is known to be configured and visible; the last-resort receipt names it. */
    private ?string $tool = null;

    public function __construct(private readonly FirewallRunner $firewall)
    {
    }

    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        $this->tool = null;

        try {
            return $this->serve($request);
        } catch (JsonRpcException $e) {
            throw $e;
        } catch (Throwable $e) {
            // Nothing escapes: the framework would report() the exception (message, trace) and, with app.debug on,
            // rethrow it, which on stdio ends the server. The caller gets the reference only.
            return ResponseBuilder::failed($request, ResponseBuilder::crashed($e, $this->tool));
        }
    }

    private function serve(JsonRpcRequest $request): JsonRpcResponse
    {
        $caller = Caller::current();
        $tools = (array) config('context-gate.tools', []);
        $registry = new ToolRegistry($tools);
        [$name, $tool] = PolicyStep::visibleTool($request, $caller, $registry);
        $this->tool = $name;
        $budget = $this->budget($caller);

        if (($refused = Admission::check($request, $caller, $name, $budget)) !== null) {
            return $refused;
        }

        $class = ToolRegistry::contractClassOf($tools[$name] ?? null);
        $checked = (new ArgumentsStep($this->firewall))->run($request, $caller, $tool, $class === null ? null : app($class), (array) $request->get('arguments', []));

        if ($checked instanceof JsonRpcResponse) {
            return $checked;
        }

        $decoded = (new FetchStep($this->firewall))->run($request, $caller, $tool, $checked['arguments']);

        return $decoded instanceof JsonRpcResponse
            ? $decoded
            : (new FirewallStep($this->firewall))->run($request, $caller, $name, $checked['contract'], $checked['ceiling'], $budget, $decoded);
    }

    /**
     * A preview counts against an empty store, so running it never uses up the caller's limits. Its receipts still
     * go to the log, marked with their source.
     */
    private function budget(Caller $caller): Budget
    {
        if (app()->bound(Preview::class)) {
            $store = Cache::build(['driver' => 'array']);

            return new Budget($store, new RateLimiter($store), $caller->id, $caller->id, self::SESSION_TTL);
        }

        $store = GateCache::store();

        return new Budget($store, new RateLimiter($store), $caller->id, $caller->id, self::SESSION_TTL);
    }
}
