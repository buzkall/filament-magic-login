<?php

namespace Arzcode\FilamentMagicLogin\Support;

use Stringable;
use UnexpectedValueException;

/**
 * Narrows the untyped values that come out of config, closures and stored payloads.
 *
 * A plain `(int)` turns a misconfigured "ten" into 0 attempts without a word; these
 * accept what a cast would sensibly convert and refuse the rest out loud.
 */
final class Cast
{
    public static function int(mixed $value): int
    {
        return match (true) {
            is_int($value) => $value,
            is_numeric($value) => (int) $value,
            default => throw self::unexpected('int', $value),
        };
    }

    public static function string(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value), $value instanceof Stringable => (string) $value,
            default => throw self::unexpected('string', $value),
        };
    }

    /**
     * An authenticatable's identifier, which Laravel types as mixed but is always a
     * key: an auto-increment int, or a UUID / ULID string.
     */
    public static function identifier(mixed $value): int|string
    {
        return is_int($value) ? $value : self::string($value);
    }

    private static function unexpected(string $expected, mixed $value): UnexpectedValueException
    {
        return new UnexpectedValueException(__('filament-magic-login::filament-magic-login.exceptions.unexpected_type', [
            'expected' => $expected,
            'type' => get_debug_type($value),
        ]));
    }
}
