<?php

namespace Arzcode\FilamentMagicLogin\Contracts;

use Arzcode\FilamentMagicLogin\Data\MagicLinkToken;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;

interface TokenRepository
{
    /**
     * Store a new token. Only the hash is ever persisted.
     */
    public function create(
        Authenticatable $user,
        string $hash,
        string $panelId,
        string $guard,
        bool $remember,
        CarbonImmutable $expiresAt,
        ?string $ip,
        ?string $userAgent,
    ): MagicLinkToken;

    /**
     * Find a token by hash within a panel. Null when unknown.
     */
    public function find(string $hash, string $panelId): ?MagicLinkToken;

    /**
     * Atomically mark a token as used. False when it was already used or gone.
     */
    public function consume(MagicLinkToken $token): bool;

    /**
     * Delete the user's unused tokens for a panel.
     */
    public function invalidateFor(Authenticatable $user, string $panelId): void;

    /**
     * Unused, unexpired tokens the user holds for a panel. Used by tests and audits.
     *
     * @return array<int, MagicLinkToken>
     */
    public function unusedFor(Authenticatable $user, string $panelId): array;
}
