<?php

namespace Arzcode\FilamentMagicLogin\Actions;

use Arzcode\FilamentMagicLogin\Contracts\MagicLinkNotification as MagicLinkNotificationContract;
use Arzcode\FilamentMagicLogin\Contracts\TokenRepository;
use Arzcode\FilamentMagicLogin\Data\MagicLinkToken;
use Arzcode\FilamentMagicLogin\Events\MagicLinkRequested;
use Arzcode\FilamentMagicLogin\MagicLoginPlugin;
use Arzcode\FilamentMagicLogin\Notifications\MagicLinkNotification;
use Arzcode\FilamentMagicLogin\Notifications\QueuedMagicLinkNotification;
use Arzcode\FilamentMagicLogin\Support\TokenGenerator;
use Carbon\CarbonImmutable;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * Mints a token, stores its hash and emails the link.
 *
 * The tail both entry points share: the login page's enumeration-safe SendMagicLink
 * and the administrator's SendMagicLinkToUser. Keeping it in one place is what stops
 * `invalidate_previous`, the queued-notification swap and the URL shape from drifting
 * apart between them.
 *
 * Every decision this class does *not* make — whether the address exists, whether the
 * user may reach the panel, how long the link should live — belongs to the caller,
 * because the two callers answer them very differently.
 */
final readonly class IssueMagicLink
{
    public function __construct(
        private TokenGenerator $tokens,
        private TokenRepository $repository,
    ) {}

    public function handle(
        Panel $panel,
        Authenticatable $user,
        bool $remember,
        int $expiresAfterMinutes,
        ?string $ip = null,
        ?string $userAgent = null,
        ?Authenticatable $issuedBy = null,
    ): MagicLinkToken {
        $plugin = MagicLoginPlugin::for($panel);
        $panelId = $panel->getId();

        if ($plugin->shouldInvalidatePrevious()) {
            $this->repository->invalidateFor($user, $panelId);
        }

        $plaintext = $this->tokens->plaintext();

        $token = $this->repository->create(
            user: $user,
            hash: $this->tokens->hash($plaintext),
            panelId: $panelId,
            guard: $panel->getAuthGuard(),
            remember: $remember,
            expiresAt: CarbonImmutable::now()->addMinutes($expiresAfterMinutes),
            ip: $ip,
            userAgent: $userAgent,
        );

        $notificationClass = $this->resolveNotificationClass($plugin);

        NotificationFacade::send($user, new $notificationClass(
            $this->buildUrl($panel, $plaintext),
            $expiresAfterMinutes,
            $panelId,
        ));

        MagicLinkRequested::dispatch($user, $token, $panelId, $issuedBy);

        return $token;
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
}
