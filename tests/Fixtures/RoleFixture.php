<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fixtures;

/**
 * Role config for tests (spec 010). A stdio caller is its OS user name, mapped to a role of the same name; the role's
 * ceiling is personal, so the call's ceiling is the transport's (firewall.ceiling), as it was under callers.
 */
final class RoleFixture
{
    /**
     * @param list<string> $tools
     * @return array<string, mixed> config values to pass to config()
     */
    public static function stdio(string $user, array $tools): array
    {
        return [
            'context-gate.roles.'.$user => ['tools' => $tools, 'ceiling' => 'personal'],
            'context-gate.stdio_roles.'.$user => $user,
        ];
    }
}
