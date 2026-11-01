<?php

declare(strict_types=1);

namespace ContextGate\Console;

use ContextGate\Settings\ConfigAudit;
use ContextGate\Settings\ConfigVersionStore;
use ContextGate\Settings\EffectiveSettings;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * gate:config:rollback {hash}. The stored version is validated against the caps in force now;
 * a violation is printed by name and nothing changes.
 *
 * @internal May change without notice.
 */
class ConfigRollbackCommand extends Command
{
    protected $signature = 'gate:config:rollback {hash : The hash of a stored version}';

    protected $description = 'Make an earlier stored version active; refused when it is above a cap in force now';

    public function handle(): int
    {
        $store = ConfigVersionStore::fromApp();
        $caller = app('context-gate.caller');
        $actor = is_string($caller) ? $caller : 'unknown';
        $hash = $this->hash();

        try {
            $active = $store->active();
            $before = $active === null ? [] : ($store->find($active) ?? []);
            $violations = $store->rollback($hash, EffectiveSettings::environment(), function () use ($store, $hash, $actor, $before): void {
                try {
                    ConfigAudit::record('config.rollback', $actor, $before, $store->find($hash) ?? []);
                } catch (Throwable) {
                    throw new RuntimeException('The audit line could not be written; nothing was applied.');
                }
            });
        } catch (RuntimeException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        if ($violations !== []) {
            foreach ($violations as $violation) {
                $this->error("{$violation['key']}: {$violation['message']}");
            }

            return self::FAILURE;
        }

        $this->info('Active version is now '.$store->active());

        return self::SUCCESS;
    }

    private function hash(): string
    {
        $hash = $this->argument('hash');

        return is_string($hash) ? $hash : '';
    }
}
