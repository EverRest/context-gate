<?php

declare(strict_types=1);

namespace ContextGate\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use ContextGate\Token\Ability;
use ContextGate\Token\Ceiling;
use ContextGate\Token\TokenIssuer;
use Laravel\Sanctum\Contracts\HasApiTokens;

class TokenCommand extends Command
{
    private const MAX_DAYS = 90;

    protected $signature = 'gate:token
        {--holder= : Holder name, stored as the token name}
        {--issuer= : Name of the issuing person}
        {--ceiling= : Ceiling: public, internal or personal}
        {--expires= : Lifetime in whole days, e.g. 30d, at most 90d}
        {--ability=* : Package ability, repeatable; only gate:read and the ceiling ability are granted}';

    protected $description = 'Issue an HTTP access token with an expiry and a ceiling; the token value is shown once';

    public function handle(): int
    {
        $holder = $this->text('holder');
        $issuer = $this->text('issuer');
        $ceiling = Ceiling::tryFrom($this->text('ceiling'));

        if ($holder === '' || $issuer === '' || $ceiling === null) {
            $this->error('--holder, --issuer and --ceiling (public, internal or personal) are required.');

            return self::FAILURE;
        }

        if (! preg_match('/^([1-9]\d*)d$/', $this->text('expires'), $matches)) {
            $this->error('--expires is required and takes whole days, e.g. --expires=30d.');

            return self::FAILURE;
        }

        if ((int) $matches[1] > self::MAX_DAYS) {
            $this->error('--expires must be at most 90d (the 90-day maximum).');

            return self::FAILURE;
        }

        // The issuer grants gate:read and exactly one ceiling ability; an --ability naming anything else would be dropped.
        $granted = [Ability::READ, Ability::CEILING_PREFIX.$ceiling->value];

        foreach ((array) $this->option('ability') as $ability) {
            if (! is_string($ability) || ! in_array($ability, $granted, true)) {
                $this->error('--ability must be gate:read or '.Ability::CEILING_PREFIX.$ceiling->value.' for this ceiling.');

                return self::FAILURE;
            }
        }

        $owner = $this->owner();

        if ($owner === null) {
            $this->error('No application user to own the token: the auth user model has no row.');

            return self::FAILURE;
        }

        $now = Carbon::now('UTC');

        try {
            $new = TokenIssuer::issue($owner, $holder, $issuer, $ceiling, $now->copy()->addDays((int) $matches[1]));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // Receipts go to the context-gate channel (stderr); metadata only, never the token value (FR-009, SC-003).
        Log::channel(config('context-gate.channel'))->info(json_encode([
            'event' => 'token.issued',
            'at' => $now->format('Y-m-d\TH:i:s\Z'),
            'issuer' => $issuer,
            'holder' => $holder,
            'ceiling' => $ceiling->value,
            'expires_at' => $new->accessToken->getAttribute('expires_at')?->format('Y-m-d\TH:i:s\Z'),
            'token_id' => $new->accessToken->getKey(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $this->info('Token issued. The token value is shown once and cannot be displayed again:');
        $this->line($new->plainTextToken);

        return self::SUCCESS;
    }

    /**
     * The application user that owns the token (Sanctum's tokenable); holder and issuer are free text.
     *
     * @return (Model&HasApiTokens)|null
     */
    private function owner(): ?Model
    {
        $class = config('auth.providers.users.model');

        if (! is_string($class) || ! is_a($class, Model::class, true) || ! is_a($class, HasApiTokens::class, true)) {
            return null;
        }

        $owner = $class::query()->first();

        return $owner instanceof HasApiTokens ? $owner : null;
    }

    private function text(string $option): string
    {
        $value = $this->option($option);

        return is_string($value) ? $value : '';
    }
}
