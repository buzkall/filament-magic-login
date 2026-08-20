<?php

namespace Arzcode\FilamentMagicLogin\Actions;

use Arzcode\FilamentMagicLogin\Contracts\TokenRepository;
use Arzcode\FilamentMagicLogin\Events\MagicLinkConsumed;
use Arzcode\FilamentMagicLogin\Events\MagicLinkRejected;
use Arzcode\FilamentMagicLogin\Exceptions\InvalidMagicLinkException;
use Arzcode\FilamentMagicLogin\Support\TokenGenerator;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;

final readonly class ConsumeMagicLink
{
    public function __construct(
        private TokenGenerator $tokens,
        private TokenRepository $repository,
    ) {}

    /**
     * @throws InvalidMagicLinkException
     */
    public function handle(Panel $panel, string $plaintext, Request $request): Authenticatable
    {
        $panelId = $panel->getId();

        $token = $this->repository->find($this->tokens->hash($plaintext), $panelId);

        if ($token === null) {
            $this->reject(InvalidMagicLinkException::invalid(), $panelId, $request);
        }

        if ($token->isUsed()) {
            $this->reject(InvalidMagicLinkException::used(), $panelId, $request);
        }

        if ($token->isExpired()) {
            $this->reject(InvalidMagicLinkException::expired(), $panelId, $request);
        }

        // A token minted for another guard must never authenticate this panel.
        if ($token->guard !== $panel->getAuthGuard()) {
            $this->reject(InvalidMagicLinkException::invalid(), $panelId, $request);
        }

        $user = $token->resolveUser();

        if ($user === null) {
            $this->reject(InvalidMagicLinkException::invalid(), $panelId, $request);
        }

        if (($user instanceof FilamentUser) && (! $user->canAccessPanel($panel))) {
            $this->reject(InvalidMagicLinkException::cannotAccessPanel(), $panelId, $request);
        }

        // Marking used before logging in closes the double-click and prefetch races.
        if (! $this->repository->consume($token)) {
            $this->reject(InvalidMagicLinkException::used(), $panelId, $request);
        }

        /** @var StatefulGuard $guard */
        $guard = $panel->auth();

        $guard->login($user, $token->remember);

        $request->session()->regenerate();

        MagicLinkConsumed::dispatch($user, $token, $panelId);

        return $user;
    }

    /**
     * @throws InvalidMagicLinkException
     */
    private function reject(InvalidMagicLinkException $exception, string $panelId, Request $request): never
    {
        MagicLinkRejected::dispatch($exception->reason, null, $panelId, $request->ip());

        throw $exception;
    }
}
