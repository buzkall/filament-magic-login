<?php

namespace Arzcode\FilamentMagicLogin\Events;

use Arzcode\FilamentMagicLogin\Data\MagicLinkToken;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class MagicLinkRequested
{
    use Dispatchable;

    public function __construct(
        public Authenticatable $user,
        public MagicLinkToken $token,
        public string $panelId,
    ) {}
}
