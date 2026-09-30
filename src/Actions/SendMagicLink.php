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

        $target = $this->reachablePanel($panel, $user);

        if ($target === null) {
            $this->blurTiming();

            MagicLinkRejected::dispatch('cannot_access_panel', $email, $panelId, $ip);

            return;
        }

        // Every setting comes from the panel the link opens, as with an admin-issued one.
        $targetPlugin = MagicLoginPlugin::for($target);

        $this->issuer->handle(
            panel: $target,
            user: $user,
            remember: $remember && $targetPlugin->shouldHonorRemember(),
            expiresAfterMinutes: $targetPlugin->getExpiresAfterMinutes(),
            ip: $ip,
            userAgent: $request->userAgent(),
            // Queued so a known address answers as fast as an unknown one. Sending over
            // SMTP inside the request takes hundreds of milliseconds to seconds, far past
            // what blurTiming() pads the unknown-user path with, so the response time
            // would reveal which addresses have an account. A queue push costs about the
            // same as the blur, and a slow or failing mail server cannot hang or break
            // the login form either. By default the "queue" is Laravel's deferred
            // connection, which sends right after the response instead of waiting for a
            // worker; see IssueMagicLink::queueConnection().
            queue: (bool) config('filament-magic-login.queue', true),
        );
    }

    /**
     * The panel asked at when it admits the user, else the first fallback that does.
     * Each candidate is asked `canAccessPanel()` in its own right, so a fallback can
     * never open a panel the user could not already sign in to.
     */
    private function reachablePanel(Panel $panel, Authenticatable $user): ?Panel
    {
        $candidates = [$panel, ...MagicLoginPlugin::for($panel)->getReachablePanels($panel)];

        foreach ($candidates as $candidate) {
            if ((! $user instanceof FilamentUser) || $user->canAccessPanel($candidate)) {
                return $candidate;
            }
        }

        return null;
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
