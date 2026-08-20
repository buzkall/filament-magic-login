<?php

namespace Arzcode\FilamentMagicLogin\Exceptions;

use Exception;

class InvalidMagicLinkException extends Exception
{
    public const REASON_INVALID = 'invalid';

    public const REASON_EXPIRED = 'expired';

    public const REASON_USED = 'used';

    public const REASON_CANNOT_ACCESS_PANEL = 'cannot_access_panel';

    final public function __construct(public readonly string $reason)
    {
        parent::__construct("Magic login link rejected: {$reason}.");
    }

    public static function invalid(): static
    {
        return new static(static::REASON_INVALID);
    }

    public static function expired(): static
    {
        return new static(static::REASON_EXPIRED);
    }

    public static function used(): static
    {
        return new static(static::REASON_USED);
    }

    public static function cannotAccessPanel(): static
    {
        return new static(static::REASON_CANNOT_ACCESS_PANEL);
    }
}
