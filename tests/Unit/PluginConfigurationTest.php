<?php

use Arzcode\FilamentMagicLogin\Enums\MagicLinkPosition;
use Arzcode\FilamentMagicLogin\MagicLoginPlugin;
use Arzcode\FilamentMagicLogin\Notifications\MagicLinkNotification;
use Arzcode\FilamentMagicLogin\Tests\Fixtures\Notifications\CustomMagicLinkNotification;

// 26
it('falls back to config when no setter is called', function (): void {
    config()->set('filament-magic-login', [
        'expires_after_minutes' => 42,
        'position' => MagicLinkPosition::EmailFieldHint,
        'rate_limit' => ['max_attempts' => 7, 'decay_seconds' => 77],
        'consume_rate_limit' => ['max_attempts' => 8, 'decay_seconds' => 88],
        'invalidate_previous' => false,
        'honor_remember' => false,
        'notification' => MagicLinkNotification::class,
        'route_path' => 'from-config',
    ]);

    $plugin = MagicLoginPlugin::make();

    expect($plugin->getId())->toBe('magic-login')
        ->and($plugin->getExpiresAfterMinutes())->toBe(42)
        ->and($plugin->getPosition())->toBe(MagicLinkPosition::EmailFieldHint)
        ->and($plugin->getRateLimitMaxAttempts())->toBe(7)
        ->and($plugin->getRateLimitDecaySeconds())->toBe(77)
        ->and($plugin->getConsumeRateLimitMaxAttempts())->toBe(8)
        ->and($plugin->getConsumeRateLimitDecaySeconds())->toBe(88)
        ->and($plugin->shouldInvalidatePrevious())->toBeFalse()
        ->and($plugin->shouldHonorRemember())->toBeFalse()
        ->and($plugin->getNotificationClass())->toBe(MagicLinkNotification::class)
        ->and($plugin->getRoutePath())->toBe('from-config')
        ->and($plugin->getRedirectUrl())->toBeNull()
        ->and($plugin->usesCustomLoginPage())->toBeFalse()
        ->and($plugin->getLabel())->toBe(__('filament-magic-login::filament-magic-login.actions.magic_link'));
});

it('lets setters override config', function (): void {
    config()->set('filament-magic-login.expires_after_minutes', 42);
    config()->set('filament-magic-login.route_path', 'from-config');

    $plugin = MagicLoginPlugin::make()
        ->expiresAfter(10)
        ->position(MagicLinkPosition::EmailFieldHint)
        ->label('Send me a login link')
        ->notification(CustomMagicLinkNotification::class)
        ->rateLimit(maxAttempts: 5, decaySeconds: 600)
        ->consumeRateLimit(maxAttempts: 6, decaySeconds: 60)
        ->redirectTo('https://example.test/dashboard')
        ->routePath('/enlace/')
        ->invalidatePrevious(false)
        ->honorRemember(false)
        ->useCustomLoginPage();

    expect($plugin->getExpiresAfterMinutes())->toBe(10)
        ->and($plugin->getPosition())->toBe(MagicLinkPosition::EmailFieldHint)
        ->and($plugin->getLabel())->toBe('Send me a login link')
        ->and($plugin->getNotificationClass())->toBe(CustomMagicLinkNotification::class)
        ->and($plugin->getRateLimitMaxAttempts())->toBe(5)
        ->and($plugin->getRateLimitDecaySeconds())->toBe(600)
        ->and($plugin->getConsumeRateLimitMaxAttempts())->toBe(6)
        ->and($plugin->getRedirectUrl())->toBe('https://example.test/dashboard')
        ->and($plugin->getRoutePath())->toBe('enlace')
        ->and($plugin->shouldInvalidatePrevious())->toBeFalse()
        ->and($plugin->shouldHonorRemember())->toBeFalse()
        ->and($plugin->usesCustomLoginPage())->toBeTrue();
});

it('evaluates closure options lazily', function (): void {
    $minutes = 5;

    $plugin = MagicLoginPlugin::make()
        ->expiresAfter(function () use (&$minutes): int {
            return $minutes;
        })
        ->label(fn (): string => 'Lazy label');

    expect($plugin->getExpiresAfterMinutes())->toBe(5);

    $minutes = 30;

    expect($plugin->getExpiresAfterMinutes())->toBe(30)
        ->and($plugin->getLabel())->toBe('Lazy label');
});

it('falls back through the admin expiry chain, setter to config to panel lifetime', function (): void {
    // Nothing configured: the panel's own link lifetime applies to the admin modal too.
    expect(MagicLoginPlugin::make()->getAdminExpiresAfterMinutes())->toBe(15);

    // An application that only says ->expiresAfter(30) means it everywhere.
    expect(MagicLoginPlugin::make()->expiresAfter(30)->getAdminExpiresAfterMinutes())->toBe(30);

    config()->set('filament-magic-login.admin.expires_after_minutes', 45);

    expect(MagicLoginPlugin::make()->expiresAfter(30)->getAdminExpiresAfterMinutes())->toBe(45);

    // The setter still wins over both.
    expect(MagicLoginPlugin::make()->adminExpiresAfter(90)->getAdminExpiresAfterMinutes())->toBe(90);
});

it('reads the admin defaults from config', function (): void {
    $plugin = MagicLoginPlugin::make();

    expect($plugin->getMaxAdminExpiresAfterMinutes())->toBe(4320)
        ->and($plugin->getExpiryPresets())->toBe([15, 60, 480, 1440, 4320])
        ->and($plugin->getAdminRateLimitMaxAttempts())->toBe(10)
        ->and($plugin->getAdminRateLimitDecaySeconds())->toBe(60)
        ->and($plugin->getAdminAbility())->toBeNull();
});

it('lets the panel override every admin default', function (): void {
    $plugin = MagicLoginPlugin::make()
        ->maxAdminExpiresAfter(120)
        ->expiryPresets([5, 30])
        ->adminRateLimit(maxAttempts: 2, decaySeconds: 30)
        ->adminAbility('sendMagicLink');

    expect($plugin->getMaxAdminExpiresAfterMinutes())->toBe(120)
        ->and($plugin->getExpiryPresets())->toBe([5, 30])
        ->and($plugin->getAdminRateLimitMaxAttempts())->toBe(2)
        ->and($plugin->getAdminRateLimitDecaySeconds())->toBe(30)
        ->and($plugin->getAdminAbility())->toBe('sendMagicLink');
});

it('treats a blank admin ability as none at all', function (): void {
    expect(MagicLoginPlugin::make()->adminAbility('')->getAdminAbility())->toBeNull();
});
