<?php

namespace Arzcode\FilamentMagicLogin\Events;

use Arzcode\FilamentMagicLogin\Data\MagicLinkToken;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class MagicLinkRequested
{
    use Dispatchable;

    /**
     * @param  Authenticatable|null  $issuedBy  The administrator who sent the link, when it was
     *                                          not the recipient who asked for it themselves.
     */
    public function __construct(
        public Authenticatable $user,
        public MagicLinkToken $token,
        public string $panelId,
        public ?Authenticatable $issuedBy = null,
    ) {}

    public function wasIssuedByAdministrator(): bool
    {
        return $this->issuedBy !== null;
    }
}
