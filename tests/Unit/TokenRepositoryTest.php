<?php

use Arzcode\FilamentMagicLogin\Contracts\TokenRepository;
use Arzcode\FilamentMagicLogin\Repositories\CacheTokenRepository;
use Arzcode\FilamentMagicLogin\Repositories\DatabaseTokenRepository;
use Arzcode\FilamentMagicLogin\Support\TokenGenerator;
use Carbon\CarbonImmutable;

function repository(string $driver): TokenRepository
{
    config()->set('filament-magic-login.storage.driver', $driver);

    app()->forgetInstance(TokenRepository::class);

    return app(TokenRepository::class);
}

dataset('drivers', ['database', 'cache']);

it('binds the driver named in config', function (string $driver): void {
    $expected = $driver === 'cache' ? CacheTokenRepository::class : DatabaseTokenRepository::class;

    expect(repository($driver))->toBeInstanceOf($expected);
})->with('drivers');

it('stores, finds and consumes a token', function (string $driver): void {
    $repository = repository($driver);
    $user = makeUser();
    $hash = app(TokenGenerator::class)->hash('plaintext');

    $created = $repository->create(
        user: $user,
        hash: $hash,
        panelId: 'admin',
        guard: 'web',
        remember: true,
        expiresAt: CarbonImmutable::now()->addMinutes(15),
        ip: '127.0.0.1',
        userAgent: 'PestUA',
    );

    expect($created->hash)->toBe($hash)
        ->and($created->remember)->toBeTrue()
        ->and($created->isValid())->toBeTrue()
        ->and($created->resolveUser()?->getAuthIdentifier())->toBe($user->getKey());

    $found = $repository->find($hash, 'admin');

    expect($found)->not->toBeNull()
        ->and($found->hash)->toBe($hash)
        ->and($repository->find($hash, 'app'))->toBeNull()
        ->and($repository->find('nope', 'admin'))->toBeNull();

    expect($repository->consume($found))->toBeTrue()
        ->and($repository->find($hash, 'admin')->isUsed())->toBeTrue()
        ->and($repository->unusedFor($user, 'admin'))->toBeEmpty();
})->with('drivers');

// Storage drivers: a second consume of the same token must lose.
it('lets exactly one consumer win', function (string $driver): void {
    $repository = repository($driver);
    $user = makeUser();
    $hash = app(TokenGenerator::class)->hash('plaintext');

    $token = $repository->create(
        user: $user,
        hash: $hash,
        panelId: 'admin',
        guard: 'web',
        remember: false,
        expiresAt: CarbonImmutable::now()->addMinutes(15),
        ip: null,
        userAgent: null,
    );

    $results = [$repository->consume($token), $repository->consume($token)];

    expect(array_filter($results))->toHaveCount(1);
})->with('drivers');

it('invalidates the user tokens for one panel only', function (string $driver): void {
    $repository = repository($driver);
    $user = makeUser();
    $other = makeUser();

    foreach ([['admin', 'a'], ['admin', 'b'], ['app', 'c']] as [$panel, $seed]) {
        $repository->create(
            user: $user,
            hash: str_repeat($seed, 64),
            panelId: $panel,
            guard: 'web',
            remember: false,
            expiresAt: CarbonImmutable::now()->addMinutes(15),
            ip: null,
            userAgent: null,
        );
    }

    $repository->create(
        user: $other,
        hash: str_repeat('d', 64),
        panelId: 'admin',
        guard: 'web',
        remember: false,
        expiresAt: CarbonImmutable::now()->addMinutes(15),
        ip: null,
        userAgent: null,
    );

    $repository->invalidateFor($user, 'admin');

    expect($repository->unusedFor($user, 'admin'))->toBeEmpty()
        ->and($repository->unusedFor($user, 'app'))->toHaveCount(1)
        ->and($repository->unusedFor($other, 'admin'))->toHaveCount(1);
})->with('drivers');

it('treats an expired token as invalid without consuming it', function (string $driver): void {
    $repository = repository($driver);
    $user = makeUser();

    $token = $repository->create(
        user: $user,
        hash: str_repeat('e', 64),
        panelId: 'admin',
        guard: 'web',
        remember: false,
        expiresAt: CarbonImmutable::now()->subMinute(),
        ip: null,
        userAgent: null,
    );

    expect($token->isExpired())->toBeTrue()
        ->and($repository->unusedFor($user, 'admin'))->toBeEmpty();
})->with('drivers');

it('rejects an unknown storage driver', function (): void {
    expect(fn () => repository('mongo'))
        ->toThrow(LogicException::class, 'Unknown filament-magic-login storage driver [mongo]');
});

it('refuses an unsafe cache store in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => repository('cache'))
        ->toThrow(LogicException::class, 'cannot be used for filament-magic-login in production');
})->afterEach(fn () => app()->detectEnvironment(fn (): string => 'testing'));

it('allows an unsafe cache store outside production', function (): void {
    expect(repository('cache'))->toBeInstanceOf(CacheTokenRepository::class);
});
