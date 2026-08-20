<?php

use Arzcode\FilamentMagicLogin\Support\TokenGenerator;

// 25
it('produces 64 character tokens', function (): void {
    $generator = new TokenGenerator;

    expect($generator->plaintext())->toHaveLength(64)
        ->and($generator->plaintext())->not->toBe($generator->plaintext());
});

it('produces stable sha256 hashes', function (): void {
    $generator = new TokenGenerator;

    expect($generator->hash('abc'))
        ->toBe(hash('sha256', 'abc'))
        ->toHaveLength(64)
        ->and($generator->hash('abc'))->toBe($generator->hash('abc'));
});
