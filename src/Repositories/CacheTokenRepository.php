<?php

namespace Arzcode\FilamentMagicLogin\Repositories;

use Arzcode\FilamentMagicLogin\Contracts\TokenRepository;
use Arzcode\FilamentMagicLogin\Data\MagicLinkToken;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Migration-free storage. Entries expire with the link, so there is no pruning
 * and no audit trail: listen to the package events if you need one.
 */
class CacheTokenRepository implements TokenRepository
{
    public const PREFIX = 'filament-magic-login';

    public const LOCK_SECONDS = 5;

    /**
     * Entries outlive the link itself so that "expired" and "already used" stay
     * distinguishable from "never existed", exactly as the database driver does.
     */
    public const RETENTION_SECONDS = 86400;

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
        $key = $this->key($panelId, $hash);

        $token = new MagicLinkToken(
            id: $key,
            authenticatableType: $this->typeOf($user),
            authenticatableId: $user->getAuthIdentifier(),
            hash: $hash,
            panelId: $panelId,
            guard: $guard,
            remember: $remember,
            expiresAt: $expiresAt,
            usedAt: null,
        );

        $this->store()->put($key, $token->toArray(), $this->ttl($expiresAt));
        $this->addToIndex($token, $this->ttl($expiresAt));

        return $token;
    }

    public function find(string $hash, string $panelId): ?MagicLinkToken
    {
        $payload = $this->store()->get($this->key($panelId, $hash));

        return is_array($payload) ? MagicLinkToken::fromArray($payload) : null;
    }

    public function consume(MagicLinkToken $token): bool
    {
        $key = $this->key($token->panelId, $token->hash);

        $claim = function () use ($key, $token): bool {
            $payload = $this->store()->get($key);

            if ((! is_array($payload)) || filled($payload['used_at'] ?? null)) {
                return false;
            }

            $payload['used_at'] = CarbonImmutable::now()->toIso8601String();

            $this->store()->put($key, $payload, $this->ttl($token->expiresAt));
            $this->removeFromIndex($token);

            return true;
        };

        $store = $this->store()->getStore();

        // Without lock support the stored `used_at` is still the gate, just without
        // the mutual exclusion that makes simultaneous hits safe.
        if (! $store instanceof LockProvider) {
            return $claim();
        }

        // The lock makes two simultaneous hits mutually exclusive, and the stored
        // `used_at` makes the loser see a used token rather than a missing one.
        // A lock that cannot be acquired yields false: the race was lost.
        return (bool) $store->lock("{$key}:lock", self::LOCK_SECONDS)->get($claim);
    }

    public function invalidateFor(Authenticatable $user, string $panelId): void
    {
        $indexKey = $this->indexKey($panelId, $this->typeOf($user), $user->getAuthIdentifier());

        foreach ($this->readIndex($indexKey) as $hash) {
            $this->store()->forget($this->key($panelId, $hash));
        }

        $this->store()->forget($indexKey);
    }

    public function unusedFor(Authenticatable $user, string $panelId): array
    {
        $indexKey = $this->indexKey($panelId, $this->typeOf($user), $user->getAuthIdentifier());

        $tokens = [];

        foreach ($this->readIndex($indexKey) as $hash) {
            $token = $this->find($hash, $panelId);

            if ($token?->isValid()) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    public function store(): Repository
    {
        return Cache::store(config('filament-magic-login.storage.cache_store'));
    }

    protected function key(string $panelId, string $hash): string
    {
        return static::PREFIX.":{$panelId}:{$hash}";
    }

    protected function indexKey(string $panelId, string $type, mixed $id): string
    {
        return static::PREFIX.':index:'.$panelId.':'.sha1($type.'|'.$id);
    }

    protected function addToIndex(MagicLinkToken $token, int $ttl): void
    {
        $key = $this->indexKey($token->panelId, $token->authenticatableType, $token->authenticatableId);

        $hashes = $this->readIndex($key);
        $hashes[] = $token->hash;

        $this->store()->put($key, array_values(array_unique($hashes)), $ttl);
    }

    protected function removeFromIndex(MagicLinkToken $token): void
    {
        $key = $this->indexKey($token->panelId, $token->authenticatableType, $token->authenticatableId);

        $hashes = array_values(array_filter(
            $this->readIndex($key),
            fn (string $hash): bool => $hash !== $token->hash,
        ));

        if ($hashes === []) {
            $this->store()->forget($key);

            return;
        }

        $this->store()->put($key, $hashes, $this->ttl($token->expiresAt));
    }

    /**
     * @return array<int, string>
     */
    protected function readIndex(string $key): array
    {
        $hashes = $this->store()->get($key);

        return is_array($hashes) ? array_values(array_filter($hashes, 'is_string')) : [];
    }

    protected function ttl(CarbonImmutable $expiresAt): int
    {
        $remaining = (int) CarbonImmutable::now()->diffInSeconds($expiresAt, false);

        return max(1, $remaining + static::RETENTION_SECONDS);
    }

    protected function typeOf(Authenticatable $user): string
    {
        return $user instanceof Model ? $user->getMorphClass() : $user::class;
    }
}
