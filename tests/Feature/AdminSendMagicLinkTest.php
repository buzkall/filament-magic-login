<?php

use Arzcode\FilamentMagicLogin\Actions\SendMagicLinkToUser;
use Arzcode\FilamentMagicLogin\Contracts\TokenRepository;
use Arzcode\FilamentMagicLogin\Enums\MagicLinkDeliveryOutcome;
use Arzcode\FilamentMagicLogin\Events\MagicLinkRequested;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    Notification::fake();
});

it('sends a link and reports the expiry it stored', function (): void {
    $user = makeUser();

    $this->travelTo(now()->startOfSecond());

    $result = sendLinkAsAdmin($user, minutes: 90);

    expect($result->outcome)->toBe(MagicLinkDeliveryOutcome::Sent)
        ->and($result->isSuccessful())->toBeTrue()
        ->and($result->expiresAfterMinutes)->toBe(90)
        ->and($result->token)->not->toBeNull()
        ->and($result->token->expiresAt->timestamp)->toBe(now()->addMinutes(90)->timestamp);

    expect(lastMagicLinkNotification($user)?->expiresAfterMinutes)->toBe(90);
});

it('falls back to the configured admin expiry, then to the panel expiry', function (): void {
    $user = makeUser();

    // No admin default configured: the panel's own lifetime is used.
    expect(sendLinkAsAdmin($user)->expiresAfterMinutes)->toBe(15);

    $this->rebootWith(['filament-magic-login.admin.expires_after_minutes' => 45]);
    Notification::fake();

    expect(sendLinkAsAdmin(makeUser())->expiresAfterMinutes)->toBe(45);
});

it('prefers the panel expiry an application configured over the package default', function (): void {
    $this->rebootWith([], fn ($plugin) => $plugin->expiresAfter(30));
    Notification::fake();

    expect(sendLinkAsAdmin(makeUser())->expiresAfterMinutes)->toBe(30);
});

it('clamps a request above the maximum in the result, the token and the email', function (): void {
    $this->rebootWith([], fn ($plugin) => $plugin->maxAdminExpiresAfter(60));
    Notification::fake();

    $user = makeUser();

    $this->travelTo(now()->startOfSecond());

    $result = sendLinkAsAdmin($user, minutes: 6000);

    expect($result->expiresAfterMinutes)->toBe(60)
        ->and($result->token->expiresAt->timestamp)->toBe(now()->addMinutes(60)->timestamp)
        ->and(lastMagicLinkNotification($user)?->expiresAfterMinutes)->toBe(60);
});

it('clamps a request below one minute', function (): void {
    expect(sendLinkAsAdmin(makeUser(), minutes: 0)->expiresAfterMinutes)->toBe(1);
});

it('refuses a user who cannot access the panel, without minting or emailing anything', function (): void {
    $user = makeUser(['can_access' => false]);

    $result = sendLinkAsAdmin($user);

    expect($result->outcome)->toBe(MagicLinkDeliveryOutcome::CannotAccessPanel)
        ->and($result->isSuccessful())->toBeFalse()
        ->and($result->token)->toBeNull()
        ->and(tokenCount($user))->toBe(0);

    Notification::assertNothingSentTo($user);
});

it('refuses a user with no email address, without minting or emailing anything', function (): void {
    $user = makeUser(['email' => '']);

    $result = sendLinkAsAdmin($user);

    expect($result->outcome)->toBe(MagicLinkDeliveryOutcome::NoEmailAddress)
        ->and($result->token)->toBeNull()
        ->and(tokenCount($user))->toBe(0);

    Notification::assertNothingSentTo($user);
});

it('never carries the remember flag into an admin-issued token', function (): void {
    $result = sendLinkAsAdmin(makeUser());

    expect($result->token->remember)->toBeFalse();
});

it('honours invalidatePrevious like the login page does', function (): void {
    $user = makeUser();

    sendLinkAsAdmin($user);
    sendLinkAsAdmin($user);

    expect(tokenCount($user))->toBe(1);

    $this->rebootWith([], fn ($plugin) => $plugin->invalidatePrevious(false));
    Notification::fake();

    $other = makeUser();

    sendLinkAsAdmin($other);
    sendLinkAsAdmin($other);

    expect(tokenCount($other))->toBe(2);
});

it('records the administrator on the requested event', function (): void {
    Event::fake([MagicLinkRequested::class]);

    $admin = makeUser();
    $target = makeUser();

    sendLinkAsAdmin($target, admin: $admin);

    Event::assertDispatched(
        MagicLinkRequested::class,
        fn (MagicLinkRequested $event): bool => $event->wasIssuedByAdministrator()
            && $event->issuedBy?->getAuthIdentifier() === $admin->getAuthIdentifier()
            && $event->user->getAuthIdentifier() === $target->getAuthIdentifier(),
    );
});

it('leaves the event unattributed when the user asked for the link themselves', function (): void {
    Event::fake([MagicLinkRequested::class]);

    $user = makeUser();

    requestLink($user->email);

    Event::assertDispatched(
        MagicLinkRequested::class,
        fn (MagicLinkRequested $event): bool => $event->issuedBy === null
            && ! $event->wasIssuedByAdministrator(),
    );
});

it('rate limits an administrator once past the allowance', function (): void {
    $this->rebootWith([], fn ($plugin) => $plugin->adminRateLimit(maxAttempts: 2, decaySeconds: 300));
    Notification::fake();

    $admin = makeUser();

    expect(sendLinkAsAdmin(makeUser(), admin: $admin)->outcome)->toBe(MagicLinkDeliveryOutcome::Sent)
        ->and(sendLinkAsAdmin(makeUser(), admin: $admin)->outcome)->toBe(MagicLinkDeliveryOutcome::Sent);

    $result = sendLinkAsAdmin(makeUser(), admin: $admin);

    expect($result->outcome)->toBe(MagicLinkDeliveryOutcome::RateLimited)
        ->and($result->availableInSeconds)->toBeGreaterThan(0)
        ->and($result->token)->toBeNull();
});

it('does not spend an attempt on a send that could never work', function (): void {
    $this->rebootWith([], fn ($plugin) => $plugin->adminRateLimit(maxAttempts: 1, decaySeconds: 300));
    Notification::fake();

    $admin = makeUser();

    expect(sendLinkAsAdmin(makeUser(['can_access' => false]), admin: $admin)->outcome)
        ->toBe(MagicLinkDeliveryOutcome::CannotAccessPanel);

    // The allowance is intact, so the next real send still goes out.
    expect(sendLinkAsAdmin(makeUser(), admin: $admin)->outcome)->toBe(MagicLinkDeliveryOutcome::Sent);
});

it('can have its rate limit switched off entirely', function (): void {
    $this->rebootWith([], fn ($plugin) => $plugin->adminRateLimit(maxAttempts: 0, decaySeconds: 300));
    Notification::fake();

    $admin = makeUser();

    foreach (range(1, 5) as $ignored) {
        expect(sendLinkAsAdmin(makeUser(), admin: $admin)->outcome)->toBe(MagicLinkDeliveryOutcome::Sent);
    }
});

it('leaves the recipient their own login-page allowance', function (): void {
    // The whole point of keying the admin limiter on the administrator: an admin sending
    // links must never use up the recipient's own self-service budget.
    $this->rebootWith([], fn ($plugin) => $plugin->rateLimit(maxAttempts: 3, decaySeconds: 300));
    Notification::fake();

    $admin = makeUser();
    $target = makeUser();

    foreach (range(1, 5) as $ignored) {
        sendLinkAsAdmin($target, admin: $admin);
    }

    Notification::fake();

    requestLink($target->email);

    expect(lastMagicLinkNotification($target))->not->toBeNull();
});

it('mints a link that actually signs the recipient in', function (): void {
    $user = makeUser();

    $url = adminMagicLinkUrl($user, minutes: 120);

    $this->get($url)->assertRedirect(Filament::getPanel('admin')->getUrl());

    $this->assertAuthenticatedAs($user, 'web');

    expect(app(TokenRepository::class)->unusedFor($user, 'admin'))->toBeEmpty();
});

it('is resolvable from the container', function (): void {
    expect(app(SendMagicLinkToUser::class))->toBeInstanceOf(SendMagicLinkToUser::class);
});
