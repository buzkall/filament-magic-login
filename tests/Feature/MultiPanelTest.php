<?php

use Arzcode\FilamentMagicLogin\Contracts\TokenRepository;
use Arzcode\FilamentMagicLogin\Events\MagicLinkRejected;
use Arzcode\FilamentMagicLogin\MagicLoginPlugin;
use Arzcode\FilamentMagicLogin\Tests\TestCase;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
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

it('sends nothing for a panel the user cannot reach unless told to look elsewhere', function (): void {
    $client = makeUser(['can_access_panels' => ['app']]);

    requestLink($client->email, 'admin');

    Notification::assertNothingSent();
});

it('sends a link for a panel the user can reach when asked at one they cannot', function (): void {
    $this->rebootWith(configurePlugin: fn (MagicLoginPlugin $plugin) => $plugin
        ->routePath('admin-link')
        ->expiresAfter(5)
        ->sendToReachablePanel());

    $client = makeUser(['can_access_panels' => ['app']]);

    $url = magicLinkUrl($client, 'admin');

    // Minted for the panel it opens, on that panel's own lifetime.
    $token = app(TokenRepository::class)->unusedFor($client, 'app')[0];

    expect($url)->toContain('/app/app-link/')
        ->and($token->expiresAt->timestamp)->toEqualWithDelta(now()->addMinutes(60)->timestamp, 5)
        ->and(app(TokenRepository::class)->unusedFor($client, 'admin'))->toBeEmpty();

    $this->get($url)->assertRedirect(Filament::getPanel('app')->getUrl());

    $this->assertAuthenticatedAs($client, 'web');
});

it('keeps a user who can reach the panel asked at on that panel', function (): void {
    $this->rebootWith(configurePlugin: fn (MagicLoginPlugin $plugin) => $plugin
        ->routePath('admin-link')
        ->sendToReachablePanel());

    $user = makeUser(['can_access_panels' => ['admin', 'app']]);

    expect(magicLinkUrl($user, 'admin'))->toContain('/admin/admin-link/');
});

it('still sends nothing when no fallback panel admits the user', function (): void {
    $this->rebootWith(configurePlugin: fn (MagicLoginPlugin $plugin) => $plugin->sendToReachablePanel(['app']));

    Event::fake([MagicLinkRejected::class]);

    $user = makeUser(['can_access_panels' => []]);

    requestLink($user->email, 'admin');

    Notification::assertNothingSent();

    Event::assertDispatched(
        MagicLinkRejected::class,
        fn (MagicLinkRejected $event): bool => $event->reason === 'cannot_access_panel'
            && $event->panelId === 'admin',
    );
});

it('skips a panel without the plugin when inferring the fallbacks', function (): void {
    TestCase::$registerPluginlessPanel = true;

    $this->rebootWith(configurePlugin: fn (MagicLoginPlugin $plugin) => $plugin->sendToReachablePanel());

    expect(MagicLoginPlugin::for(Filament::getPanel('admin'))->getReachablePanels(Filament::getPanel('admin')))
        ->toHaveCount(1)
        ->sequence(fn ($panel) => $panel->getId()->toBe('app'));
});

it('refuses a named fallback panel that does not register the plugin', function (): void {
    TestCase::$registerPluginlessPanel = true;

    $this->rebootWith(configurePlugin: fn (MagicLoginPlugin $plugin) => $plugin->sendToReachablePanel(['bare']));

    $client = makeUser(['can_access_panels' => ['bare']]);

    expect(fn () => requestLink($client->email, 'admin'))->toThrow(LogicException::class);
});
