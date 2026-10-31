<?php

declare(strict_types=1);

namespace ContextGate\Server;

use ContextGate\Classification\Catalog;
use ContextGate\Contract\ContextContract;
use ContextGate\Contract\ContractValidator;
use InvalidArgumentException;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Contracts\Transport;

class GateServer extends Server
{
    public const VERSION = '0.1.0';

    protected string $name = 'context-gate';

    protected string $version = self::VERSION;

    protected array $tools = [];

    public function __construct(Transport $transport)
    {
        parent::__construct($transport);

        $this->addMethod('tools/list', GateListTools::class);
        $this->addMethod('tools/call', GateCallTool::class);
    }

    protected function boot(): void
    {
        if (app()->environment('production')) {
            fwrite(STDERR, "context-gate: refusing to start over stdio in production; use authenticated HTTP.\n");

            exit(1);
        }

        // FR-016: an invalid tool entry stops the server here, not on the first call.
        new ToolRegistry(config('context-gate.tools', []));

        // stdout belongs to the protocol: every line here goes to stderr only. The server keeps running.
        foreach (self::contractRefusals() as $tool => $rule) {
            fwrite(STDERR, "context-gate: tool [{$tool}] has a contract that breaks a load rule: {$rule}\n");
        }

        if (($warning = self::canaryWarning()) !== null) {
            fwrite(STDERR, $warning."\n");
        }
    }

    /**
     * Tool name => the load rule its contract breaks. Reported at boot; a relation rule is also refused per call
     * before the handler (FirewallRunner::argumentsRefusal).
     *
     * @return array<string, string>
     */
    public static function contractRefusals(): array
    {
        /** @var array<string, array<string, mixed>> $map */
        $map = config('context-gate.classification', []);
        $refusals = [];

        foreach ((array) config('context-gate.tools', []) as $name => $entry) {
            $contract = is_array($entry) ? ($entry['contract'] ?? null) : null;

            if (! is_string($contract) || ! is_a($contract, ContextContract::class, true)) {
                continue;
            }

            try {
                (new ContractValidator())->validate(new $contract(), new Catalog($map));
            } catch (InvalidArgumentException $e) {
                $refusals[(string) $name] = $e->getMessage();
            }
        }

        return $refusals;
    }

    /** The stderr line when the gate is enabled and no canary value is configured; null otherwise. */
    public static function canaryWarning(): ?string
    {
        if (config('context-gate.enabled') !== true || config('context-gate.canaries.values', []) !== []) {
            return null;
        }

        return 'context-gate: context-gate.canaries.values is empty; canary protection is off.';
    }
}
