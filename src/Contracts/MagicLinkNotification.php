<?php

namespace Arzcode\FilamentMagicLogin\Contracts;

/**
 * The constructor contract every magic-link notification must satisfy.
 *
 * Implement this alongside extending Laravel's Notification (or simply extend
 * Arzcode\FilamentMagicLogin\Notifications\MagicLinkNotification, which already does).
 */
interface MagicLinkNotification
{
    public function __construct(string $url, int $expiresAfterMinutes, string $panelId);
}
