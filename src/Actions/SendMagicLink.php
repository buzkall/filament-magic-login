<?php

namespace Arzcode\FilamentMagicLogin\Actions;

use Arzcode\FilamentMagicLogin\Events\MagicLinkRejected;
use Arzcode\FilamentMagicLogin\MagicLoginPlugin;
use Arzcode\FilamentMagicLogin\Support\UserProviderResolver;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

final readonly class SendMagicLink
{
    public function __construct(
        private IssueMagicLink $issuer,
        private UserProviderResolver $providers,
    ) {}

    /**
     * Always silent: the caller shows the same confirmation whether or not a link
     * was actually sent, so the login form never confirms an address exists.
     */
    public function handle(Panel $panel, string $email, bool $remember, Request $request): void
    {
        $plugin = MagicLoginPlugin::for($panel);
        $panelId = $panel->getId();
        $ip = $request->ip();

        $rateLimitKey = $this->rateLimitKey($email, $ip);

        if (RateLimiter::tooManyAttempts($rateLimitKey, $plugin->getRateLimitMaxAttempts())) {
            MagicLinkRejected::dispatch('rate_limited', $email, $panelId, $ip);

            return;
        }

        RateLimiter::hit($rateLimitKey, $plugin->getRateLimitDecaySeconds());

        $user = $this->findUser($panel->getAuthGuard(), $email);

        if ($user === null) {
            $this->blurTiming();

            MagicLinkRejected::dispatch('unknown_user', $email, $panelId, $ip);

            return;
        }

        if (($user instanceof FilamentUser) && (! $user->canAccessPanel($panel))) {
            $this->blurTiming();

            MagicLinkRejected::dispatch('cannot_access_panel', $email, $panelId, $ip);

            return;
        }

        $this->issuer->handle(
            panel: $panel,
            user: $user,
            remember: $remember && $plugin->shouldHonorRemember(),
            expiresAfterMinutes: $plugin->getExpiresAfterMinutes(),
            ip: $ip,
            userAgent: $request->userAgent(),
        );
    }

    private function findUser(string $guard, string $email): ?Authenticatable
    {
        return $this->providers->for($guard)?->retrieveByCredentials(['email' => $email]);
    }

    private function rateLimitKey(string $email, ?string $ip): string
    {
        return 'filament-magic-login:request:'.sha1(Str::lower($email).'|'.$ip);
    }

    /**
     * Pads the unknown-user path so response time does not reveal whether the
     * address is registered.
     */
    private function blurTiming(): void
    {
        if (! config('filament-magic-login.blur_timing', true)) {
            return;
        }

        usleep(random_int(50_000, 150_000));
    }
}
