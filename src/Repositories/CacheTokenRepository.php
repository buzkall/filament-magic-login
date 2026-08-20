<?php

namespace Arzcode\FilamentMagicLogin\Repositories;

use Arzcode\FilamentMagicLogin\Contracts\TokenRepository;
use Arzcode\FilamentMagicLogin\Data\MagicLinkToken;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
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

        $ttl = $this->ttl($expiresAt);

        $this->store()->put($key, $token->toArray(), $ttl);
        $this->addToIndex($token, $ttl);

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

        // The lock makes two simultaneous hits mutually exclusive; `pull` makes the
        // winner the only one that finds a payload. A lost lock returns false.
        return (bool) $this->store()
            ->lock("{$key}:lock", static::LOCK_SECONDS)
            ->get(function () use ($key, $token): bool {
                $payload = $this->store()->pull($key);

                if (! is_array($payload)) {
                    return false;
                }

                $this->removeFromIndex($token);

                return true;
            });
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
        return static::PREFIX . ":{$panelId}:{$hash}";
    }

    protected function indexKey(string $panelId, string $type, mixed $id): string
    {
        return static::PREFIX . ':index:' . $panelId . ':' . sha1($type . '|' . $id);
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
        return max(1, (int) CarbonImmutable::now()->diffInSeconds($expiresAt, false));
    }

    protected function typeOf(Authenticatable $user): string
    {
        return $user instanceof Model ? $user->getMorphClass() : $user::class;
    }
}
