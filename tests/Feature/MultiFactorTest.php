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
 * Filament 5 performs its multi-factor challenge inside the login page's
 * `authenticate()` method rather than in middleware, so a magic link — which
 * authenticates through the panel guard directly — does not present it. The link
 * itself is the second factor (possession of the mailbox); README documents this.
 */
it('signs in through a magic link without the challenge', function (): void {
    $user = makeUser();

    $this->get(magicLinkUrl($user));

    $this->assertAuthenticatedAs($user, 'web');
});
