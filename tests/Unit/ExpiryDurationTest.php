<?php

use Arzcode\FilamentMagicLogin\Support\ExpiryDuration;

beforeEach(function (): void {
    app()->setLocale('en');
});

it('keeps an hour or less in the minutes the administrator chose', function (int $minutes, string $expected): void {
    expect(ExpiryDuration::describe($minutes))->toBe($expected);
})->with([
    [1, '1 minute'],
    [15, '15 minutes'],
    [45, '45 minutes'],
    [60, '60 minutes'],
]);

it('cascades anything longer into hours and days', function (int $minutes, string $expected): void {
    expect(ExpiryDuration::describe($minutes))->toBe($expected);
})->with([
    [61, '1 hour 1 minute'],
    [90, '1 hour 30 minutes'],
    [480, '8 hours'],
    [1440, '1 day'],
    [4320, '3 days'],
]);

it('speaks the language the application is set to', function (string $locale, string $expected): void {
    app()->setLocale($locale);

    expect(ExpiryDuration::describe(4320))->toBe($expected);
})->with([
    ['es', '3 días'],
    ['ca', '3 dies'],
]);
