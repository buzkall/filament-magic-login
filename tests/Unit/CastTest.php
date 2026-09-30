<?php

use Arzcode\FilamentMagicLogin\Support\Cast;

beforeEach(function (): void {
    app()->setLocale('en');
});

it('passes integers through and converts numeric values', function (mixed $value, int $expected): void {
    expect(Cast::int($value))->toBe($expected);
})->with([
    [10, 10],
    ['10', 10],
    [' 10', 10],
    [10.9, 10],
    ['-5', -5],
]);

it('refuses a value that is not a number instead of turning it into zero', function (mixed $value, string $type): void {
    expect(fn () => Cast::int($value))
        ->toThrow(UnexpectedValueException::class, "Expected a value of type [int], got [{$type}].");
})->with([
    ['ten', 'string'],
    ['', 'string'],
    [null, 'null'],
    [true, 'bool'],
    [[10], 'array'],
]);

it('passes strings through and converts scalars and stringables', function (mixed $value, string $expected): void {
    expect(Cast::string($value))->toBe($expected);
})->with([
    ['info', 'info'],
    ['', ''],
    [42, '42'],
    [1.5, '1.5'],
    [str('stringable'), 'stringable'],
]);

it('refuses a value that has no sensible string form', function (mixed $value, string $type): void {
    expect(fn () => Cast::string($value))
        ->toThrow(UnexpectedValueException::class, "Expected a value of type [string], got [{$type}].");
})->with([
    [null, 'null'],
    [false, 'bool'],
    [['info'], 'array'],
    [new stdClass, 'stdClass'],
]);

it('keeps an integer identifier an integer and a key string a string', function (mixed $value, int|string $expected): void {
    expect(Cast::identifier($value))->toBe($expected);
})->with([
    [7, 7],
    ['7', '7'],
    ['01J9ZQ4M5V8K2X3N6P7R8S9T0W', '01J9ZQ4M5V8K2X3N6P7R8S9T0W'],
]);

it('refuses an identifier that is not a key', function (): void {
    expect(fn () => Cast::identifier(null))
        ->toThrow(UnexpectedValueException::class, 'Expected a value of type [string], got [null].');
});

it('says so in the application language', function (): void {
    app()->setLocale('es');

    expect(fn () => Cast::int('diez'))
        ->toThrow(UnexpectedValueException::class, 'Se esperaba un valor de tipo [int], pero se recibió [string].');
});
