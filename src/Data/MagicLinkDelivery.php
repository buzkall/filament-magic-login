<?php

namespace Arzcode\FilamentMagicLogin\Data;

use Arzcode\FilamentMagicLogin\Enums\MagicLinkDeliveryOutcome;

/**
 * The result of an administrator-issued link.
 *
 * Carries the effective expiry rather than leaving the caller to recompute it: the
 * requested minutes are clamped to the configured ceiling, so what was asked for and
 * what was stored are not always the same number, and the confirmation the
 * administrator reads must quote the one that was stored.
 */
final readonly class MagicLinkDelivery
{
    public function __construct(
        public MagicLinkDeliveryOutcome $outcome,
        public int $expiresAfterMinutes,
        public ?MagicLinkToken $token = null,
        public ?int $availableInSeconds = null,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->outcome->isSuccessful();
    }

    public static function sent(MagicLinkToken $token, int $expiresAfterMinutes): self
    {
        return new self(MagicLinkDeliveryOutcome::Sent, $expiresAfterMinutes, $token);
    }

    public static function refused(
        MagicLinkDeliveryOutcome $outcome,
        int $expiresAfterMinutes,
        ?int $availableInSeconds = null,
    ): self {
        return new self($outcome, $expiresAfterMinutes, null, $availableInSeconds);
    }
}
