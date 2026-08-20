<?php

use Arzcode\FilamentMagicLogin\Notifications\MagicLinkNotification;
use Arzcode\FilamentMagicLogin\Tests\Fixtures\Models\SpanishUser;
use Illuminate\Mail\MailManager;

it('renders the email in every shipped locale', function (string $locale, string $button): void {
    app()->setLocale($locale);

    $user = makeUser();

    $html = (string) (new MagicLinkNotification('https://example.test/admin/magic-login/abc', 15, 'admin'))
        ->toMail($user)
        ->render();

    expect($html)
        ->toContain($button)
        ->toContain('https://example.test/admin/magic-login/abc')
        ->toContain('15')
        ->not->toContain('filament-magic-login::');
})->with([
    'en' => ['en', 'Sign in'],
    'es' => ['es', 'Entrar'],
    'ca' => ['ca', 'Entrar'],
]);

it('sends the email in the notifiable preferred locale', function (): void {
    app()->setLocale('en');

    $user = SpanishUser::query()->create([
        'name' => 'Usuaria',
        'email' => 'usuaria@example.com',
        'password' => 'password',
    ]);

    $user->notify(new MagicLinkNotification('https://example.test/admin/magic-login/abc', 15, 'admin'));

    $body = app(MailManager::class)->mailer('array')->getSymfonyTransport()
        ->messages()->first()->getOriginalMessage()->toString();

    expect(quoted_printable_decode($body))
        ->toContain('Entrar')
        ->not->toContain('Sign in');
});
