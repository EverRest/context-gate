<?php

declare(strict_types=1);

namespace ContextGate\Console;

use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use ContextGate\Token\Ability;
use ContextGate\Token\Ceiling;
use ContextGate\Token\GateAccessToken;
use ContextGate\Token\TokenIssuer;

/** @internal May change without notice. */
class TokenCommand extends Command
{
    protected $signature = 'gate:token
        {--holder= : Holder name, stored as the token name}
        {--issuer= : Name of the issuing person}
        {--ceiling= : Ceiling: public, internal or personal}
        {--expires= : Lifetime in whole days, e.g. 30d, at most 90d}
        {--ability=* : Package ability, repeatable; only gate:read and the ceiling ability are granted}
        {--list : List active tokens (id, holder, issuer, caller, ceiling, expires_at, issued_at); no token values}
        {--json : With --list, print the tokens as JSON}
        {--revoke= : Revoke the token with this id}';

    protected $description = 'Issue an HTTP access token with an expiry and a ceiling; the token value is shown once';

    public function handle(): int
    {
        if ($this->option('list')) {
            return $this->listTokens();
        }

        if (is_string($this->option('revoke'))) {
            return $this->revokeToken($this->option('revoke'));
        }

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

        if ((int) $matches[1] > TokenIssuer::MAX_DAYS) {
            $this->error('--expires must be at most '.TokenIssuer::MAX_DAYS.'d (the maximum).');

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

        $now = Carbon::now('UTC');

        try {
            $new = TokenIssuer::issue($holder, $issuer, $ceiling, $now->copy()->addDays((int) $matches[1]));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // Receipts go to the context-gate channel (stderr); metadata only, never the token value.
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

        $caller = 'token:'.$new->accessToken->getKey();
        $this->line("Caller key: {$caller}");
        $this->line("Add this line inside 'callers' in config/context-gate.php, with the tools it may use; until then it sees no tools:");
        $this->line("    '{$caller}' => ['tools' => []],");

        return self::SUCCESS;
    }

    private function listTokens(): int
    {
        $issuers = DB::table('gate_token_issuers')->pluck('issuer', 'token_id');
        $rows = [];

        // Active means not yet expired; a revoked token has no row left to list.
        $active = GateAccessToken::query();
        $active->where('expires_at', '>', Carbon::now('UTC'))->orderBy('id');

        foreach ($active->get() as $token) {
            $rows[] = [
                'id' => $token->getKey(),
                'holder' => $token->getAttribute('name'),
                'issuer' => $issuers[$token->getKey()] ?? '',
                'caller' => 'token:'.$token->getKey(),
                'ceiling' => $this->ceilingOf($token),
                'expires_at' => $this->utc($token->getAttribute('expires_at')),
                'issued_at' => $this->utc($token->getAttribute('created_at')),
            ];
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(array_map(static fn (array $row): array => [
                'id' => $row['id'], 'holder' => $row['holder'], 'issuer' => $row['issuer'], 'ceiling' => $row['ceiling'],
                'expires_at' => $row['expires_at'], 'issued_at' => $row['issued_at'], 'caller' => $row['caller'],
            ], $rows), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->table(['id', 'holder', 'issuer', 'caller', 'ceiling', 'expires_at', 'issued_at'], $rows);

        return self::SUCCESS;
    }

    /**
     * Deletes the token and sets its issuer row's token_id to null in one transaction, so the issuer record outlives
     * the token. Revoking an id with no token (unknown, or revoked before) changes nothing and exits 1.
     */
    private function revokeToken(string $id): int
    {
        $token = GateAccessToken::query()->find($id);

        if ($token === null) {
            $this->error("No token with id {$id}: it is unknown or already revoked.");

            return self::FAILURE;
        }

        $connection = $token->getConnection();
        $issuer = $connection->table('gate_token_issuers')->where('token_id', $token->getKey())->value('issuer');

        $connection->transaction(function () use ($connection, $token): void {
            $connection->table('gate_token_issuers')->where('token_id', $token->getKey())->update(['token_id' => null]);
            $token->delete();
        });

        // Metadata only, same channel as token.issued.
        Log::channel(config('context-gate.channel'))->info(json_encode([
            'event' => 'token.revoked',
            'at' => Carbon::now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'id' => 'r_'.bin2hex(random_bytes(4)),
            'issuer' => $issuer,
            'holder' => $token->getAttribute('name'),
            'token_id' => $token->getKey(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $this->info("Token {$id} revoked.");

        return self::SUCCESS;
    }

    private function ceilingOf(GateAccessToken $token): string
    {
        $abilities = $token->getAttribute('abilities');
        $ceiling = collect(is_array($abilities) ? $abilities : [])->first(
            fn (mixed $ability): bool => is_string($ability) && str_starts_with($ability, Ability::CEILING_PREFIX),
        );

        return is_string($ceiling) ? str_replace(Ability::CEILING_PREFIX, '', $ceiling) : '';
    }

    private function utc(mixed $value): string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d\TH:i:s\Z') : '';
    }

    private function text(string $option): string
    {
        $value = $this->option($option);

        return is_string($value) ? $value : '';
    }
}
