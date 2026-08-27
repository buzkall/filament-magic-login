<?php

use Arzcode\FilamentMagicLogin\Contracts\TokenRepository;
use Arzcode\FilamentMagicLogin\MagicLoginPlugin;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->rebootWith(
        configurePlugin: fn (MagicLoginPlugin $plugin) => $plugin->routePath('admin-link')->expiresAfter(5),
        configureAppPlugin: fn (MagicLoginPlugin $plugin) => $plugin->routePath('app-link')->expiresAfter(60),
    );

    Notification::fake();
});

it('runs two panels with different route paths and lifetimes at once', function (): void {
    $user = makeUser();

    $adminUrl = magicLinkUrl($user, 'admin');
    $appUrl = magicLinkUrl($user, 'app');

    expect($adminUrl)->toContain('/admin/admin-link/')
        ->and($appUrl)->toContain('/app/app-link/');

    $adminToken = app(TokenRepository::class)->unusedFor($user, 'admin')[0];
    $appToken = app(TokenRepository::class)->unusedFor($user, 'app')[0];

    expect($adminToken->expiresAt->timestamp)->toEqualWithDelta(now()->addMinutes(5)->timestamp, 5)
        ->and($appToken->expiresAt->timestamp)->toEqualWithDelta(now()->addMinutes(60)->timestamp, 5);

    // Each link signs the user into its own panel and leaves the other untouched.
    $this->get($appUrl)->assertRedirect(Filament::getPanel('app')->getUrl());

    $this->assertAuthenticatedAs($user, 'web');

    expect(app(TokenRepository::class)->unusedFor($user, 'admin'))->toHaveCount(1)
        ->and(app(TokenRepository::class)->unusedFor($user, 'app'))->toBeEmpty();
});

it('issues a token per panel without invalidating the other panel', function (): void {
    $user = makeUser();

    magicLinkUrl($user, 'admin');
    magicLinkUrl($user, 'app');
    magicLinkUrl($user, 'admin');

    expect(app(TokenRepository::class)->unusedFor($user, 'admin'))->toHaveCount(1)
        ->and(app(TokenRepository::class)->unusedFor($user, 'app'))->toHaveCount(1);
});

it('keeps each panel on its own admin settings', function (): void {
    $this->rebootWith(
        configurePlugin: fn (MagicLoginPlugin $plugin) => $plugin->maxAdminExpiresAfter(60),
        configureAppPlugin: fn (MagicLoginPlugin $plugin) => $plugin->maxAdminExpiresAfter(1440),
    );

    Notification::fake();

    expect(MagicLoginPlugin::for(Filament::getPanel('admin'))->getMaxAdminExpiresAfterMinutes())->toBe(60)
        ->and(MagicLoginPlugin::for(Filament::getPanel('app'))->getMaxAdminExpiresAfterMinutes())->toBe(1440);

    // The clamp follows the panel the link is minted for, not the one asking.
    expect(sendLinkAsAdmin(makeUser(), minutes: 600, panelId: 'admin')->expiresAfterMinutes)->toBe(60)
        ->and(sendLinkAsAdmin(makeUser(), minutes: 600, panelId: 'app')->expiresAfterMinutes)->toBe(600);
});

it('mints an admin-issued link against the panel it was asked for', function (): void {
    $user = makeUser();

    $url = adminMagicLinkUrl($user, minutes: 30, panelId: 'app');

    expect($url)->toContain('/app/app-link/')
        ->and(app(TokenRepository::class)->unusedFor($user, 'app'))->toHaveCount(1)
        ->and(app(TokenRepository::class)->unusedFor($user, 'admin'))->toBeEmpty();
});
