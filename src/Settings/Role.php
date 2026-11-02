<?php

declare(strict_types=1);

namespace ContextGate\Settings;

use ContextGate\Classification\Ceiling;

/**
 * A named set of tools, a ceiling and the limits a caller of the role gets. Read through ActiveSettings::roles(); the
 * limits are the values in use: a role's own value, or the profile value when it sets none (validated not to exceed it).
 *
 * @internal May change without notice.
 */
final readonly class Role
{
    /** Role limit name => cap key of the profile value it may only lower (EffectiveSettings). */
    public const LIMIT_KEYS = [
        'per_call_bytes' => 'firewall.limits.per_call_bytes',
        'per_session_bytes' => 'firewall.limits.per_session_bytes',
        'per_day_bytes' => 'firewall.limits.per_day_bytes',
        'calls_per_minute' => 'firewall.limits.calls_per_minute',
    ];

    /**
     * @param list<string> $tools
     * @param array<string, int|float> $limits limit name => value in use
     */
    public function __construct(
        public string $name,
        public array $tools,
        public Ceiling $ceiling,
        public array $limits,
    ) {
    }

    /**
     * Built from a document that has passed validation.
     *
     * @param array<string, mixed> $document
     * @param array<string, int|float> $profile cap key => profile value in use (ActiveSettings::limits())
     * @return array<string, self>
     */
    public static function all(array $document, array $profile): array
    {
        $roles = [];

        foreach ((array) ($document['roles'] ?? []) as $name => $role) {
            $role = (array) $role;
            $limits = [];

            foreach (self::LIMIT_KEYS as $limit => $key) {
                $limits[$limit] = $role['limits'][$limit] ?? $profile[$key];
            }

            $roles[(string) $name] = new self(
                (string) $name,
                array_values(array_map(strval(...), (array) ($role['tools'] ?? []))),
                Ceiling::from((string) $role['ceiling']),
                $limits,
            );
        }

        return $roles;
    }
}
