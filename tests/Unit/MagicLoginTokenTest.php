<?php

use Arzcode\FilamentMagicLogin\Data\MagicLinkToken;
use Arzcode\FilamentMagicLogin\Models\MagicLoginToken;

/**
 * @param  array<string, mixed>  $attributes
 */
function storedToken(array $attributes = []): MagicLoginToken
{
    return MagicLoginToken::query()->create([
        'authenticatable_type' => 'user',
        'authenticatable_id' => 1,
        'token_hash' => str_repeat('a', 64),
        'panel_id' => 'admin',
        'guard' => 'web',
        'remember' => false,
        'expires_at' => now()->addMinutes(15),
        ...$attributes,
    ]);
}

it('is valid while unused and unexpired', function (): void {
    $token = storedToken();

    expect($token->isExpired())->toBeFalse()
        ->and($token->isUsed())->toBeFalse()
        ->and($token->isValid())->toBeTrue();
});

it('is no longer valid once its expiry has passed', function (): void {
    $token = storedToken(['expires_at' => now()->subSecond()]);

    expect($token->isExpired())->toBeTrue()
        ->and($token->isUsed())->toBeFalse()
        ->and($token->isValid())->toBeFalse();
});

it('is no longer valid once used, and records when', function (): void {
    $this->freezeTime();

    $token = storedToken();

    $token->markUsed();

    expect($token->isUsed())->toBeTrue()
        ->and($token->isExpired())->toBeFalse()
        ->and($token->isValid())->toBeFalse()
        ->and($token->fresh()->used_at?->timestamp)->toBe(now()->timestamp);
});

it('carries every stored column over to the data object', function (): void {
    $this->freezeTime();

    $token = storedToken([
        'authenticatable_id' => 42,
        'token_hash' => str_repeat('b', 64),
        'panel_id' => 'app',
        'guard' => 'admin',
        'remember' => true,
        'expires_at' => now()->addHour(),
        'used_at' => now()->subMinute(),
    ])->fresh();

    $data = $token->toData();

    expect($data)->toBeInstanceOf(MagicLinkToken::class)
        ->and($data->id)->toBe((string) $token->getKey())
        ->and($data->authenticatableType)->toBe('user')
        ->and($data->authenticatableId)->toEqual(42)
        ->and($data->hash)->toBe(str_repeat('b', 64))
        ->and($data->panelId)->toBe('app')
        ->and($data->guard)->toBe('admin')
        ->and($data->remember)->toBeTrue()
        ->and($data->expiresAt->timestamp)->toBe(now()->addHour()->timestamp)
        ->and($data->usedAt?->timestamp)->toBe(now()->subMinute()->timestamp)
        ->and($data->isUsed())->toBeTrue();
});

it('leaves usedAt empty on the data object for an unused token', function (): void {
    expect(storedToken()->fresh()->toData()->usedAt)->toBeNull();
});
