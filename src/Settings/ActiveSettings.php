<?php

declare(strict_types=1);

namespace ContextGate\Settings;

use ContextGate\Classification\Ceiling;
use Illuminate\Support\Arr;
use InvalidArgumentException;

/**
 * The configuration in force, validated once when the instance is built, and the only way package code reads it. Typed getters return the value in use. On an invalid document every getter throws InvalidSettings,
 * except channel(), valid(), violations(), document() and environment(): a call is refused with config.invalid.
 *
 * @internal May change without notice.
 */
final class ActiveSettings
{
    private const CHANNEL = 'context-gate';

    /** @var list<array{key: string, message: string}> */
    private readonly array $violations;

    /** @var array<string, int|float> */
    private readonly array $limits;

    /** @var array<string, array<string, string>> */
    private readonly array $classification;

    /**
     * @param array<string, mixed> $document the settings document in config
     * @param list<array{key: string, message: string}> $overlay violations of the stored versions (ConfigOverlay)
     */
    public function __construct(private readonly array $document, private readonly string $env, array $overlay = [])
    {
        $this->violations = [...SettingsValidator::violations($document, $env), ...$overlay];
        $valid = $this->violations === [];

        $this->limits = $valid ? EffectiveSettings::for($document, $env) : [];
        $this->classification = $valid ? ClassificationMerge::effective((array) ($document['classification'] ?? [])) : [];
    }

    /** The document in config, with the stored version applied. Boot code builds its own instance rather than the singleton. */
    public static function fromApp(\Illuminate\Contracts\Foundation\Application $app): self
    {
        $document = config('context-gate');
        $env = config('app.env');

        return new self(is_array($document) ? $document : [], is_string($env) && $env !== '' ? $env : 'production', $app->make('context-gate.overlay'));
    }

    public function valid(): bool
    {
        return $this->violations === [];
    }

    /** @return list<array{key: string, message: string}> */
    public function violations(): array
    {
        return $this->violations;
    }

    /** @return array<string, mixed> */
    public function document(): array
    {
        return $this->document;
    }

    public function environment(): string
    {
        return $this->env;
    }

    public function enabled(): bool
    {
        return (bool) ($this->ready()['enabled'] ?? false);
    }

    /** @return array<string, ToolEntry> */
    public function tools(): array
    {
        return self::toolEntries($this->ready());
    }

    /**
     * The tool entries of a document. Boot builds the registry from them even when the document is invalid.
     *
     * @param array<string, mixed> $document
     * @return array<string, ToolEntry>
     */
    public static function toolEntries(array $document): array
    {
        $entries = [];

        foreach ((array) ($document['tools'] ?? []) as $name => $tool) {
            $entries[(string) $name] = ToolEntry::fromConfig((string) $name, (array) $tool);
        }

        return $entries;
    }

    /** The stdio ceiling: public or internal (the document is validated, so the value is one of the two). */
    public function ceiling(): Ceiling
    {
        return Ceiling::tryFrom((string) Arr::get($this->ready(), 'firewall.ceiling', 'internal')) ?? Ceiling::Internal;
    }

    /** @return array<string, list<string>> caller id => tool names */
    public function callers(): array
    {
        $callers = [];

        foreach ((array) ($this->ready()['callers'] ?? []) as $id => $caller) {
            $callers[(string) $id] = self::strings((array) $caller, 'tools');
        }

        return $callers;
    }

    /** @return list<string> */
    public function canaries(): array
    {
        return $this->strings($this->ready(), 'canaries.values');
    }

    /** @return array<string, array<string, string>> the effective classification: built-in tables merged with the host's */
    public function classification(): array
    {
        $this->ready();

        return $this->classification;
    }

    /** @return array<string, int|float> cap key => value in use */
    public function limits(): array
    {
        $this->ready();

        return $this->limits;
    }

    /** The value in use for one limit key (profile, caps and environment applied). */
    public function limit(string $key): int|float
    {
        return $this->limits()[$key] ?? throw new InvalidArgumentException("No limit [{$key}].");
    }

    public function httpPath(): string
    {
        return (string) Arr::get($this->ready(), 'http.path');
    }

    /** The receipt channel. It has a value on an invalid document too, so the config.invalid refusal writes its receipt. */
    public function channel(): string
    {
        $channel = $this->document['channel'] ?? null;

        return is_string($channel) && $channel !== '' ? $channel : self::CHANNEL;
    }

    /** @return list<string> */
    public function origins(): array
    {
        return $this->strings($this->ready(), 'http.origins');
    }

    /** @return list<string> */
    public function openApiAllowlist(): array
    {
        return $this->strings($this->ready(), 'sources.openapi.allowlist');
    }

    /** @return list<string> */
    public function docsPaths(): array
    {
        return $this->strings($this->ready(), 'sources.docs.paths');
    }

    /** @return list<string> */
    public function openApiPaths(): array
    {
        return $this->strings($this->ready(), 'sources.openapi.paths');
    }

    /** @return list<string> */
    public function models(): array
    {
        return $this->strings($this->ready(), 'sources.eloquent.models');
    }

    /** @return list<string> */
    public function routeExcludes(): array
    {
        return $this->strings($this->ready(), 'sources.routes.exclude');
    }

    /** @return array<string, string> config key => env name */
    public function envMap(): array
    {
        return array_filter((array) Arr::get($this->ready(), 'sources.config.env_map', []), 'is_string');
    }

    /** The OpenAPI example mode in use: drop or redacted. */
    public function examples(): string
    {
        return (string) Arr::get($this->ready(), 'sources.openapi.examples', 'drop');
    }

    public function cacheStore(): ?string
    {
        return self::nullableString($this->ready()['cache_store'] ?? null);
    }

    /** @return array<string, string> runbook name => class */
    public function runbooks(): array
    {
        return array_filter((array) ($this->ready()['runbooks'] ?? []), 'is_string');
    }

    /**
     * The document, when it validated.
     *
     * @return array<string, mixed>
     */
    private function ready(): array
    {
        if ($this->violations !== []) {
            throw new InvalidSettings('The settings are invalid; read violations() and fix the keys named there.');
        }

        return $this->document;
    }

    /** @return list<string> */
    /**
     * @param array<string, mixed> $document
     * @return list<string>
     */
    private function strings(array $document, string $key): array
    {
        return array_values(array_filter((array) Arr::get($document, $key, []), 'is_string'));
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
