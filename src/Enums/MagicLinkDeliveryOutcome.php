<?php

namespace Arzcode\FilamentMagicLogin\Enums;

/**
 * What became of an administrator's request to send a user a login link.
 *
 * Unlike the login page — which is deliberately silent so an anonymous visitor cannot
 * probe which addresses exist — the administrator is told exactly what happened. They
 * can already read the users table, so none of these answers leaks anything new.
 */
enum MagicLinkDeliveryOutcome: string
{
    case Sent = 'sent';

    case NoEmailAddress = 'no_email_address';

    case CannotAccessPanel = 'cannot_access_panel';

    case RateLimited = 'rate_limited';

    public function isSuccessful(): bool
    {
        return $this === self::Sent;
    }
}
