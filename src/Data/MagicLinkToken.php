<?php

namespace Arzcode\FilamentMagicLogin\Data;

use Arzcode\FilamentMagicLogin\Support\UserProviderResolver;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;

final readonly class MagicLinkToken
{
    public function __construct(
        public string $id,
        public string $authenticatableType,
        public int|string $authenticatableId,
        public string $hash,
        public string $panelId,
        public string $guard,
        public bool $remember,
        public CarbonImmutable $expiresAt,
        public ?CarbonImmutable $usedAt,
    ) {}

    public function isExpired(): bool
    {
        return $this->expiresAt->isPast();
    }

    public function isUsed(): bool
    {
        return $this->usedAt !== null;
    }

    public function isValid(): bool
    {
        return ! $this->isExpired() && ! $this->isUsed();
    }

    /**
     * Resolved through the guard's own user provider so the host app's model
     * (and any custom provider) is respected.
     */
    public function resolveUser(): ?Authenticatable
    {
        $provider = app(UserProviderResolver::class)->for($this->guard);

        if ($provider === null) {
            return null;
        }

        $user = $provider->retrieveById($this->authenticatableId);

        if ($user === null) {
            return null;
        }

        return $this->matchesType($user) ? $user : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'authenticatable_type' => $this->authenticatableType,
            'authenticatable_id' => $this->authenticatableId,
            'hash' => $this->hash,
            'panel_id' => $this->panelId,
            'guard' => $this->guard,
            'remember' => $this->remember,
            'expires_at' => $this->expiresAt->toIso8601String(),
            'used_at' => $this->usedAt?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            authenticatableType: (string) $data['authenticatable_type'],
            authenticatableId: $data['authenticatable_id'],
            hash: (string) $data['hash'],
            panelId: (string) $data['panel_id'],
            guard: (string) $data['guard'],
            remember: (bool) $data['remember'],
            expiresAt: CarbonImmutable::parse($data['expires_at']),
            usedAt: filled($data['used_at'] ?? null) ? CarbonImmutable::parse($data['used_at']) : null,
        );
    }

    private function matchesType(Authenticatable $user): bool
    {
        $type = method_exists($user, 'getMorphClass')
            ? $user->getMorphClass()
            : $user::class;

        return $type === $this->authenticatableType || $user instanceof $this->authenticatableType;
    }
}
