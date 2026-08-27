<?php

namespace Arzcode\FilamentMagicLogin\Actions;

use Arzcode\FilamentMagicLogin\Data\MagicLinkDelivery;
use Arzcode\FilamentMagicLogin\Enums\MagicLinkDeliveryOutcome;
use Arzcode\FilamentMagicLogin\MagicLoginPlugin;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Sends a login link to a user an administrator picked, rather than to one who asked
 * for it themselves.
 *
 * The deliberate mirror image of SendMagicLink: no timing blur, no uniform silence,
 * and a rate limit keyed on the administrator instead of the recipient. Every refusal
 * comes back as a distinct outcome, because the person reading it is authenticated and
 * already able to see the users table.
 */
final readonly class SendMagicLinkToUser
{
    public function __construct(private IssueMagicLink $issuer) {}

    public function handle(
        Panel $panel,
        Authenticatable $user,
        ?int $expiresAfterMinutes = null,
        ?Authenticatable $issuedBy = null,
        ?Request $request = null,
    ): MagicLinkDelivery {
        $plugin = MagicLoginPlugin::for($panel);

        // Resolved once and reused for the stored expiry, the email and the result:
        // reading it three times is how the email ends up promising a lifetime the
        // token does not have.
        $minutes = $this->effectiveMinutes($plugin, $expiresAfterMinutes);

        // Before anything else: Laravel's mail channel returns early when a notifiable
        // has no address, so without this the administrator would be told "sent" for a
        // link nobody can receive.
        if (! $this->hasEmailAddress($user)) {
            return MagicLinkDelivery::refused(MagicLinkDeliveryOutcome::NoEmailAddress, $minutes);
        }

        // Ahead of the rate limiter on purpose, so a send that could never work does
        // not spend one of the administrator's attempts.
        if (($user instanceof FilamentUser) && (! $user->canAccessPanel($panel))) {
            return MagicLinkDelivery::refused(MagicLinkDeliveryOutcome::CannotAccessPanel, $minutes);
        }

        $ip = $request?->ip();
        $key = $this->rateLimitKey($panel->getId(), $issuedBy, $ip);
        $maxAttempts = $plugin->getAdminRateLimitMaxAttempts();

        if ($maxAttempts > 0) {
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                return MagicLinkDelivery::refused(
                    MagicLinkDeliveryOutcome::RateLimited,
                    $minutes,
                    RateLimiter::availableIn($key),
                );
            }

            RateLimiter::hit($key, $plugin->getAdminRateLimitDecaySeconds());
        }

        $token = $this->issuer->handle(
            panel: $panel,
            user: $user,
            // Never carried over: whether a session persists is the recipient's own
            // choice on the login form, not something an administrator decides for them.
            remember: false,
            expiresAfterMinutes: $minutes,
            // The administrator's, not the recipient's — the row records who asked.
            ip: $ip,
            userAgent: $request?->userAgent(),
            issuedBy: $issuedBy,
        );

        return MagicLinkDelivery::sent($token, $minutes);
    }

    /**
     * Clamped rather than rejected: the modal's own validation is what tells a person
     * they asked for too long, and this is the backstop for every other caller.
     */
    private function effectiveMinutes(MagicLoginPlugin $plugin, ?int $requested): int
    {
        return max(1, min(
            $requested ?? $plugin->getAdminExpiresAfterMinutes(),
            $plugin->getMaxAdminExpiresAfterMinutes(),
        ));
    }

    /**
     * Whether a notification sent to this user has anywhere to go. Mail routing may be
     * a plain address or the array form Laravel accepts, so this asks only whether one
     * is present rather than what it is.
     */
    private function hasEmailAddress(Authenticatable $user): bool
    {
        $route = match (true) {
            $user instanceof CanResetPassword => $user->getEmailForPasswordReset(),
            method_exists($user, 'routeNotificationFor') => $user->routeNotificationFor('mail'),
            default => $user->email ?? null,
        };

        return filled(is_string($route) ? trim($route) : $route);
    }

    /**
     * Keyed on the administrator, in its own namespace. Sharing the login page's
     * `filament-magic-login:request:` key would let an administrator's sends lock the
     * recipient out of asking for a link themselves.
     */
    private function rateLimitKey(string $panelId, ?Authenticatable $issuedBy, ?string $ip): string
    {
        $issuer = $issuedBy === null
            ? 'anonymous'
            : $issuedBy::class.'|'.$issuedBy->getAuthIdentifier();

        return 'filament-magic-login:admin:'.sha1($panelId.'|'.$issuer.'|'.$ip);
    }
}
