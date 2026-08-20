<?php

namespace Arzcode\FilamentMagicLogin\Models;

use Arzcode\FilamentMagicLogin\Data\MagicLinkToken;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property string $authenticatable_type
 * @property int|string $authenticatable_id
 * @property string $token_hash
 * @property string $panel_id
 * @property string $guard
 * @property bool $remember
 * @property string|null $requested_ip
 * @property string|null $requested_user_agent
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $used_at
 */
class MagicLoginToken extends Model
{
    use Prunable;

    /**
     * Keeps a day of used and expired rows for audit before they are pruned.
     */
    public const AUDIT_RETENTION_HOURS = 24;

    /**
     * @var array<string>
     */
    protected $guarded = [];

    public function getTable(): string
    {
        return $this->table ?? config('filament-magic-login.storage.table', 'magic_login_tokens');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function authenticatable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where(
            'expires_at',
            '<',
            now()->subHours(static::AUDIT_RETENTION_HOURS),
        );
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeUnused(Builder $query): void
    {
        $query->whereNull('used_at');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeUnexpired(Builder $query): void
    {
        $query->where('expires_at', '>', now());
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeForPanel(Builder $query, string $panelId): void
    {
        $query->where('panel_id', $panelId);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function isValid(): bool
    {
        return ! $this->isExpired() && ! $this->isUsed();
    }

    public function markUsed(): void
    {
        $this->forceFill(['used_at' => now()])->save();
    }

    public function toData(): MagicLinkToken
    {
        return new MagicLinkToken(
            id: (string) $this->getKey(),
            authenticatableType: $this->authenticatable_type,
            authenticatableId: $this->authenticatable_id,
            hash: $this->token_hash,
            panelId: $this->panel_id,
            guard: $this->guard,
            remember: $this->remember,
            expiresAt: $this->expires_at,
            usedAt: $this->used_at,
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'remember' => 'bool',
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
        ];
    }
}
