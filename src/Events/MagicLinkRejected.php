<?php

namespace Arzcode\FilamentMagicLogin\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class MagicLinkRejected
{
    use Dispatchable;

    /**
     * @param  string  $reason  One of the reasons on InvalidMagicLinkException, or "rate_limited" / "unknown_user".
     */
    public function __construct(
        public string $reason,
        public ?string $email,
        public string $panelId,
        public ?string $ip,
    ) {}
}
