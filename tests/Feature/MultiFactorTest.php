<?php

use Arzcode\FilamentMagicLogin\Pages\Login;
use Arzcode\FilamentMagicLogin\Tests\Fixtures\MultiFactor\AlwaysOnMultiFactorProvider;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->rebootWith(
        configurePanel: fn ($panel) => $panel->multiFactorAuthentication([new AlwaysOnMultiFactorProvider]),
    );

    Notification::fake();
    Filament::setCurrentPanel('admin');
});

it('holds password logins at the multi-factor challenge', function (): void {
    $user = makeUser();

    livewire(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate');

    // Filament's challenge lives in the login page, so the password alone is not enough.
    expect(auth()->guard('web')->check())->toBeFalse();
});

/**
 * By design: clicking a link delivered to the mailbox already proves possession of
 * a second factor, so the magic link is not challenged again. Filament 5 keeps its
 * challenge inside the login page's `authenticate()` method, and this package
 * authenticates through the panel guard directly.
 */
it('signs in through a magic link without the challenge', function (): void {
    $user = makeUser();

    $this->get(magicLinkUrl($user));

    $this->assertAuthenticatedAs($user, 'web');
});

it('reaches the panel after a magic link login on a panel that requires 2fa', function (): void {
    $this->rebootWith(
        configurePanel: fn ($panel) => $panel->multiFactorAuthentication(
            [new AlwaysOnMultiFactorProvider],
            isRequired: true,
        ),
    );

    Notification::fake();
    Filament::setCurrentPanel('admin');

    $user = makeUser();

    $this->get(magicLinkUrl($user));

    $this->assertAuthenticatedAs($user, 'web');

    // Enrolment is satisfied, so the guarded pages open normally.
    $this->get(Filament::getPanel('admin')->getUrl())->assertOk();
});

/**
 * Bypassing the *challenge* must not bypass the *enrolment* requirement: a panel
 * that forces every user to register a second factor still sends them to the set-up
 * page, exactly as it would after a password login.
 */
it('still forces 2fa enrolment after a magic link login', function (): void {
    $this->rebootWith(
        configurePanel: fn ($panel) => $panel->multiFactorAuthentication(
            [new AlwaysOnMultiFactorProvider],
            isRequired: true,
        ),
    );

    Notification::fake();
    Filament::setCurrentPanel('admin');

    AlwaysOnMultiFactorProvider::$enabled = false;

    $user = makeUser();

    $this->get(magicLinkUrl($user));

    $this->assertAuthenticatedAs($user, 'web');

    $this->get(Filament::getPanel('admin')->getUrl())
        ->assertRedirect(Filament::getPanel('admin')->getSetUpRequiredMultiFactorAuthenticationUrl());
});
