<?php

namespace Arzcode\FilamentMagicLogin\Support;

use Illuminate\Support\Str;

final readonly class TokenGenerator
{
    public const PLAINTEXT_LENGTH = 64;

    /**
     * A high-entropy, single-use secret. Never persisted.
     */
    public function plaintext(): string
    {
        return Str::random(self::PLAINTEXT_LENGTH);
    }

    /**
     * Tokens are random, not passwords: a fast hash keeps lookups by hash possible
     * without needing a separate selector column.
     */
    public function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}
