<?php

namespace Arzcode\FilamentMagicLogin\Repositories;

use Arzcode\FilamentMagicLogin\Contracts\TokenRepository;
use Arzcode\FilamentMagicLogin\Data\MagicLinkToken;
use Arzcode\FilamentMagicLogin\Models\MagicLoginToken;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

class DatabaseTokenRepository implements TokenRepository
{
    public function create(
        Authenticatable $user,
        string $hash,
        string $panelId,
        string $guard,
        bool $remember,
        CarbonImmutable $expiresAt,
        ?string $ip,
        ?string $userAgent,
    ): MagicLinkToken {
        $token = MagicLoginToken::query()->create([
            'authenticatable_type' => $this->typeOf($user),
            'authenticatable_id' => $user->getAuthIdentifier(),
            'token_hash' => $hash,
            'panel_id' => $panelId,
            'guard' => $guard,
            'remember' => $remember,
            'requested_ip' => $ip,
            'requested_user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 512),
            'expires_at' => $expiresAt,
        ]);

        return $token->toData();
    }

    public function find(string $hash, string $panelId): ?MagicLinkToken
    {
        return MagicLoginToken::query()
            ->where('token_hash', $hash)
            ->forPanel($panelId)
            ->first()
            ?->toData();
    }

    public function consume(MagicLinkToken $token): bool
    {
        // A conditional update is what makes this single-use: a second click,
        // or an email scanner racing the human, updates zero rows.
        return MagicLoginToken::query()
            ->whereKey($token->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]) === 1;
    }

    public function invalidateFor(Authenticatable $user, string $panelId): void
    {
        MagicLoginToken::query()
            ->where('authenticatable_type', $this->typeOf($user))
            ->where('authenticatable_id', $user->getAuthIdentifier())
            ->forPanel($panelId)
            ->unused()
            ->delete();
    }

    public function unusedFor(Authenticatable $user, string $panelId): array
    {
        return MagicLoginToken::query()
            ->where('authenticatable_type', $this->typeOf($user))
            ->where('authenticatable_id', $user->getAuthIdentifier())
            ->forPanel($panelId)
            ->unused()
            ->unexpired()
            ->get()
            ->map(fn (MagicLoginToken $token): MagicLinkToken => $token->toData())
            ->all();
    }

    protected function typeOf(Authenticatable $user): string
    {
        return $user instanceof Model ? $user->getMorphClass() : $user::class;
    }
}
