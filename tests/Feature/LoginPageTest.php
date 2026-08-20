<?php

use Arzcode\FilamentMagicLogin\Contracts\TokenRepository;
use Arzcode\FilamentMagicLogin\Enums\MagicLinkPosition;
use Arzcode\FilamentMagicLogin\Events\MagicLinkRejected;
use Arzcode\FilamentMagicLogin\Notifications\MagicLinkNotification;
use Arzcode\FilamentMagicLogin\Notifications\QueuedMagicLinkNotification;
use Arzcode\FilamentMagicLogin\Pages\Login;
use Arzcode\FilamentMagicLogin\Tests\Fixtures\Notifications\CustomMagicLinkNotification;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    Notification::fake();
    RateLimiter::clear('x');
});

// 1
it('renders the magic link action below the form by default', function (): void {
    $page = livewire(Login::class)
        ->assertActionExists('magicLink')
        ->instance();

    expect(actionNames(callProtected($page, 'getFormActions')))
        ->toContain('authenticate', 'magicLink')
        ->and(hintActionNames(callProtected($page, 'getEmailFormComponent')))
        ->toBe([]);
});

// 2
it('renders the magic link action as an email field hint', function (): void {
    $this->rebootWith(['filament-magic-login.position' => MagicLinkPosition::EmailFieldHint]);

    $page = livewire(Login::class)
        ->assertActionExists('magicLink')
        ->instance();

    expect(hintActionNames(callProtected($page, 'getEmailFormComponent')))
        ->toBe(['magicLink'])
        ->and(actionNames(callProtected($page, 'getFormActions')))
        ->not->toContain('magicLink');
});

// 3
it('adds a validation error and sends nothing when the email is empty', function (): void {
    livewire(Login::class)
        ->fillForm(['email' => '', 'password' => ''])
        ->callAction('magicLink')
        ->assertHasErrors('data.email');

    Notification::assertNothingSent();
    expect(tokenCount())->toBe(0);
});

// 4
it('shows the same confirmation and sends nothing for an unknown email', function (): void {
    livewire(Login::class)
        ->fillForm(['email' => 'nobody@example.com'])
        ->callAction('magicLink')
        ->assertHasNoErrors()
        ->assertNotified();

    Notification::assertNothingSent();
    expect(tokenCount())->toBe(0);
});

// 5
it('sends a link and stores one token for a known email', function (): void {
    $user = makeUser();

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->callAction('magicLink')
        ->assertNotified();

    Notification::assertSentTo($user, QueuedMagicLinkNotification::class);

    $tokens = app(TokenRepository::class)->unusedFor($user, 'admin');

    expect($tokens)->toHaveCount(1)
        ->and($tokens[0]->panelId)->toBe('admin')
        ->and($tokens[0]->guard)->toBe('web')
        ->and($tokens[0]->expiresAt->timestamp)
        ->toEqualWithDelta(now()->addMinutes(15)->timestamp, 5);
});

// 6
it('persists the remember checkbox on the token when honorRemember is on', function (): void {
    $user = makeUser();

    livewire(Login::class)
        ->fillForm(['email' => $user->email, 'remember' => true])
        ->callAction('magicLink');

    expect(app(TokenRepository::class)->unusedFor($user, 'admin')[0]->remember)->toBeTrue();
});

it('ignores the remember checkbox when honorRemember is off', function (): void {
    $this->rebootWith(['filament-magic-login.honor_remember' => false]);

    $user = makeUser();

    livewire(Login::class)
        ->fillForm(['email' => $user->email, 'remember' => true])
        ->callAction('magicLink');

    expect(app(TokenRepository::class)->unusedFor($user, 'admin')[0]->remember)->toBeFalse();
});

// 7
it('leaves one unused token when invalidatePrevious is on', function (): void {
    $user = makeUser();

    requestLink($user->email);
    requestLink($user->email);

    expect(app(TokenRepository::class)->unusedFor($user, 'admin'))->toHaveCount(1);
});

it('leaves both unused tokens when invalidatePrevious is off', function (): void {
    $this->rebootWith(['filament-magic-login.invalidate_previous' => false]);

    $user = makeUser();

    requestLink($user->email);
    requestLink($user->email);

    expect(app(TokenRepository::class)->unusedFor($user, 'admin'))->toHaveCount(2);
});

// 8
it('stays silent but still confirms once the request rate limit is exceeded', function (): void {
    Event::fake([MagicLinkRejected::class]);

    $user = makeUser();

    foreach (range(1, 3) as $ignored) {
        requestLink($user->email);
    }

    Notification::assertSentTimes(QueuedMagicLinkNotification::class, 3);

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->callAction('magicLink')
        ->assertHasNoErrors()
        ->assertNotified();

    Notification::assertSentTimes(QueuedMagicLinkNotification::class, 3);

    Event::assertDispatched(
        MagicLinkRejected::class,
        fn (MagicLinkRejected $event): bool => $event->reason === 'rate_limited',
    );
});

// 9
it('sends nothing to a user who cannot access the panel', function (): void {
    Event::fake([MagicLinkRejected::class]);

    $user = makeUser(['can_access' => false]);

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->callAction('magicLink')
        ->assertNotified();

    Notification::assertNothingSent();
    expect(tokenCount())->toBe(0);

    Event::assertDispatched(
        MagicLinkRejected::class,
        fn (MagicLinkRejected $event): bool => $event->reason === 'cannot_access_panel',
    );
});

// 10
it('dispatches the notification class configured on the plugin', function (): void {
    $this->rebootWith([], fn ($plugin) => $plugin->notification(CustomMagicLinkNotification::class));

    $user = makeUser();

    requestLink($user->email);

    Notification::assertSentTo($user, CustomMagicLinkNotification::class);
    Notification::assertNotSentTo($user, QueuedMagicLinkNotification::class);
});

it('sends the unqueued notification when queueing is disabled', function (): void {
    $this->rebootWith(['filament-magic-login.queue' => false]);

    $user = makeUser();

    requestLink($user->email);

    Notification::assertSentTo($user, MagicLinkNotification::class);
});

// 11
it('still authenticates with a password', function (): void {
    $user = makeUser();

    livewire(Login::class)
        ->fillForm([
            'email' => $user->email,
            'password' => 'password',
        ])
        ->call('authenticate')
        ->assertHasNoErrors();

    expect(auth()->guard('web')->check())->toBeTrue();
});

it('rejects a wrong password', function (): void {
    $user = makeUser();

    livewire(Login::class)
        ->fillForm([
            'email' => $user->email,
            'password' => 'wrong-password',
        ])
        ->call('authenticate')
        ->assertHasErrors('data.email');

    expect(auth()->guard('web')->check())->toBeFalse();
});
