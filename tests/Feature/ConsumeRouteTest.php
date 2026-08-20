<?php

use Arzcode\FilamentMagicLogin\Contracts\TokenRepository;
use Arzcode\FilamentMagicLogin\Events\MagicLinkConsumed;
use Arzcode\FilamentMagicLogin\Events\MagicLinkRejected;
use Arzcode\FilamentMagicLogin\Support\TokenGenerator;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    Notification::fake();
});

// 12
it('authenticates the user and redirects to the panel', function (): void {
    Event::fake([MagicLinkConsumed::class]);

    $user = makeUser();
    $url = magicLinkUrl($user);

    $this->get($url)->assertRedirect(Filament::getPanel('admin')->getUrl());

    $this->assertAuthenticatedAs($user, 'web');

    expect(app(TokenRepository::class)->unusedFor($user, 'admin'))->toBeEmpty();

    Event::assertDispatched(
        MagicLinkConsumed::class,
        fn (MagicLinkConsumed $event): bool => $event->panelId === 'admin'
            && $event->user->getAuthIdentifier() === $user->getAuthIdentifier(),
    );
});

it('regenerates the session id', function (): void {
    $user = makeUser();
    $url = magicLinkUrl($user);

    $this->startSession();
    $before = session()->getId();

    $this->get($url);

    expect(session()->getId())->not->toBe($before);
});

// 13
it('sets the remember cookie when the token was issued with remember', function (): void {
    $user = makeUser();

    $this->get(magicLinkUrl($user, remember: true))
        ->assertCookie(Auth::guard('web')->getRecallerName());
});

it('sets no remember cookie otherwise', function (): void {
    $user = makeUser();

    $this->get(magicLinkUrl($user))
        ->assertCookieMissing(Auth::guard('web')->getRecallerName());
});

// 14
it('rejects an expired token', function (): void {
    $user = makeUser();
    $url = magicLinkUrl($user);

    $this->travelTo(now()->addMinutes(16));

    $this->get($url)->assertRedirect(Filament::getPanel('admin')->getLoginUrl());

    $this->assertGuest('web');

    expect(flashedNotificationBodies())->toContain(
        __('filament-magic-login::filament-magic-login.messages.invalid_reason.expired'),
    );
});

// 15
it('rejects an already used token', function (): void {
    $user = makeUser();
    $url = magicLinkUrl($user);

    $this->get($url);
    $this->flushSession();
    Auth::guard('web')->logout();

    $this->get($url)->assertRedirect(Filament::getPanel('admin')->getLoginUrl());

    $this->assertGuest('web');

    expect(flashedNotificationBodies())->toContain(
        __('filament-magic-login::filament-magic-login.messages.invalid_reason.used'),
    );
});

// 16
it('rejects a garbage token', function (): void {
    Event::fake([MagicLinkRejected::class]);

    $this->get('/admin/magic-login/' . str_repeat('a', 64))
        ->assertRedirect(Filament::getPanel('admin')->getLoginUrl());

    $this->assertGuest('web');

    expect(flashedNotificationBodies())->toContain(
        __('filament-magic-login::filament-magic-login.messages.invalid_reason.invalid'),
    );

    Event::assertDispatched(
        MagicLinkRejected::class,
        fn (MagicLinkRejected $event): bool => $event->reason === 'invalid',
    );
});

// 17
it('refuses a token issued for another panel', function (): void {
    $user = makeUser();

    $url = magicLinkUrl($user, panelId: 'app');
    $token = basename(parse_url($url, PHP_URL_PATH));

    expect($url)->toContain('/app/magic-login/');

    $this->get("/admin/magic-login/{$token}")
        ->assertRedirect(Filament::getPanel('admin')->getLoginUrl());

    $this->assertGuest('web');

    // The token still works on the panel it was minted for.
    $this->get($url);

    $this->assertAuthenticatedAs($user, 'web');
});

// 18
it('refuses a token whose user lost panel access', function (): void {
    $user = makeUser();
    $url = magicLinkUrl($user);

    $user->update(['can_access' => false]);

    $this->get($url)->assertRedirect(Filament::getPanel('admin')->getLoginUrl());

    $this->assertGuest('web');

    expect(flashedNotificationBodies())->toContain(
        __('filament-magic-login::filament-magic-login.messages.invalid_reason.cannot_access_panel'),
    );
});

// 19
it('does not consume the token for an already authenticated visitor', function (): void {
    $user = makeUser();
    $url = magicLinkUrl($user);

    $this->actingAs($user, 'web');

    $this->get($url)->assertRedirect();

    expect(app(TokenRepository::class)->unusedFor($user, 'admin'))->toHaveCount(1);
});

// 20
it('does not consume the token on a HEAD request', function (): void {
    $user = makeUser();
    $url = magicLinkUrl($user);

    $this->head($url)->assertNoContent();

    $this->assertGuest('web');

    expect(app(TokenRepository::class)->unusedFor($user, 'admin'))->toHaveCount(1);

    // ...and the link still works for the human afterwards.
    $this->get($url);

    $this->assertAuthenticatedAs($user, 'web');
});

// 21
it('throttles the consume route by ip', function (): void {
    config()->set('filament-magic-login.consume_rate_limit.max_attempts', 3);

    $garbage = '/admin/magic-login/' . str_repeat('b', 64);

    foreach (range(1, 3) as $ignored) {
        $this->get($garbage)->assertRedirect();
    }

    $this->get($garbage)->assertStatus(429);
});

// 22
it('honours a custom redirectTo closure', function (): void {
    $this->rebootWith([], fn ($plugin) => $plugin->redirectTo(fn (): string => url('/admin/somewhere')));

    $user = makeUser();

    $this->get(magicLinkUrl($user))->assertRedirect(url('/admin/somewhere'));

    $this->assertAuthenticatedAs($user, 'web');
});

// 23
it('honours a custom route path in both the url and the email', function (): void {
    $this->rebootWith(['filament-magic-login.route_path' => 'enlace']);

    $user = makeUser();
    $url = magicLinkUrl($user);

    expect($url)->toContain('/admin/enlace/')
        ->and($url)->not->toContain('/magic-login/');

    $this->get($url);

    $this->assertAuthenticatedAs($user, 'web');
});

it('never stores the plaintext token', function (): void {
    $user = makeUser();
    $url = magicLinkUrl($user);
    $plaintext = basename(parse_url($url, PHP_URL_PATH));

    $stored = app(TokenRepository::class)->unusedFor($user, 'admin')[0];

    expect($stored->hash)->toBe(app(TokenGenerator::class)->hash($plaintext))
        ->and($stored->hash)->not->toBe($plaintext);
});
