<?php

namespace Arzcode\FilamentMagicLogin\Actions;

use Arzcode\FilamentMagicLogin\Contracts\MagicLinkNotification as MagicLinkNotificationContract;
use Arzcode\FilamentMagicLogin\Contracts\TokenRepository;
use Arzcode\FilamentMagicLogin\Events\MagicLinkRejected;
use Arzcode\FilamentMagicLogin\Events\MagicLinkRequested;
use Arzcode\FilamentMagicLogin\MagicLoginPlugin;
use Arzcode\FilamentMagicLogin\Notifications\MagicLinkNotification;
use Arzcode\FilamentMagicLogin\Notifications\QueuedMagicLinkNotification;
use Arzcode\FilamentMagicLogin\Support\TokenGenerator;
use Arzcode\FilamentMagicLogin\Support\UserProviderResolver;
use Carbon\CarbonImmutable;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

final readonly class SendMagicLink
{
    public function __construct(
        private TokenGenerator $tokens,
        private TokenRepository $repository,
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

        $guard = $panel->getAuthGuard();
        $user = $this->findUser($guard, $email);

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

        if ($plugin->shouldInvalidatePrevious()) {
            $this->repository->invalidateFor($user, $panelId);
        }

        $plaintext = $this->tokens->plaintext();
        $minutes = $plugin->getExpiresAfterMinutes();

        $token = $this->repository->create(
            user: $user,
            hash: $this->tokens->hash($plaintext),
            panelId: $panelId,
            guard: $guard,
            remember: $remember && $plugin->shouldHonorRemember(),
            expiresAt: CarbonImmutable::now()->addMinutes($minutes),
            ip: $ip,
            userAgent: $request->userAgent(),
        );

        $notificationClass = $this->resolveNotificationClass($plugin);

        NotificationFacade::send($user, new $notificationClass(
            $this->buildUrl($panel, $plaintext),
            $minutes,
            $panelId,
        ));

        MagicLinkRequested::dispatch($user, $token, $panelId);
    }

    private function findUser(string $guard, string $email): ?Authenticatable
    {
        return $this->providers->for($guard)?->retrieveByCredentials(['email' => $email]);
    }

    private function buildUrl(Panel $panel, string $plaintext): string
    {
        return route("filament.{$panel->getId()}.magic-login.consume", ['token' => $plaintext]);
    }

    /**
     * @return class-string<MagicLinkNotificationContract>
     */
    private function resolveNotificationClass(MagicLoginPlugin $plugin): string
    {
        $class = $plugin->getNotificationClass();

        // Queueing a custom notification is the developer's own decision.
        if ($class === MagicLinkNotification::class && config('filament-magic-login.queue', true)) {
            return QueuedMagicLinkNotification::class;
        }

        return $class;
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
