<?php

declare(strict_types=1);

namespace ContextGate\Server;

use ContextGate\Settings\ActiveSettings;
use ContextGate\Classification\Ceiling;
use ContextGate\Settings\Role;
use Throwable;

/** The caller of a call: its id, its role (null when none), and the tools its role grants. */
final readonly class Caller
{
    /** @param list<string> $tools */
    public function __construct(
        public string $id,
        public array $tools,
        public ?string $role = null,
    ) {
    }

    public static function current(): self
    {
        $id = app('context-gate.caller');
        $settings = app(ActiveSettings::class);
        $role = self::roleName($id, $settings);

        // An invalid document grants nothing; the call is refused as config.invalid before any tool is looked up.
        $definition = $settings->valid() && $role !== null ? self::definitionOf($role, $settings) : null;
        $tools = $definition === null ? [] : $definition->tools;

        return new self($id, $tools, $role);
    }

    /** For the last-resort handlers: the caller, or a placeholder when even that lookup fails. */
    public static function orUnknown(): self
    {
        try {
            return self::current();
        } catch (Throwable) {
            return new self('unknown', []);
        }
    }

    /** The role definition in config, or null when the caller has no role or the role is not defined (role.unknown). */
    public function definition(): ?Role
    {
        $settings = app(ActiveSettings::class);

        return $this->role === null || ! $settings->valid() ? null : self::definitionOf($this->role, $settings);
    }

    /**
     * The ceiling of this call: the narrower of the role's ceiling and the transport's. Over HTTP the transport is the
     * token's ceiling, bound in the container ('' when the token has none; a null instance would read as unbound);
     * otherwise the configured firewall.ceiling, for stdio. Null means fail closed: GateCallTool refuses the call as
     * config.invalid.
     */
    public function effectiveCeiling(): ?Ceiling
    {
        $role = $this->definition()?->ceiling;
        $transport = self::transportCeiling();

        return $role === null || $transport === null ? null : Ceiling::narrower($transport, $role);
    }

    /** The effective ceiling of the current caller (see effectiveCeiling). */
    public static function ceiling(): ?Ceiling
    {
        return self::current()->effectiveCeiling();
    }

    /** Over HTTP the token's role name, bound by the server ('' when none); over stdio the mapping of the OS user name. */
    private static function roleName(string $id, ActiveSettings $settings): ?string
    {
        if (app()->bound('context-gate.role')) {
            $value = app('context-gate.role');

            return is_string($value) && $value !== '' ? $value : null;
        }

        return $settings->valid() ? ($settings->stdioRoles()[$id] ?? null) : null;
    }

    private static function definitionOf(string $role, ActiveSettings $settings): ?Role
    {
        return $settings->roles()[$role] ?? null;
    }

    private static function transportCeiling(): ?Ceiling
    {
        if (! app()->bound('context-gate.ceiling')) {
            $settings = app(ActiveSettings::class);

            return $settings->valid() ? $settings->ceiling() : null;
        }

        $value = app('context-gate.ceiling');

        return is_string($value) ? Ceiling::tryFrom($value) : null;
    }
}
