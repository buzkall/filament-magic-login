<?php

namespace Arzcode\FilamentMagicLogin\Notifications;

use Arzcode\FilamentMagicLogin\Contracts\MagicLinkNotification as MagicLinkNotificationContract;
use Arzcode\FilamentMagicLogin\Support\ExpiryDuration;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MagicLinkNotification extends Notification implements MagicLinkNotificationContract
{
    use Queueable;

    public function __construct(
        public readonly string $url,
        public readonly int $expiresAfterMinutes,
        public readonly string $panelId,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('filament-magic-login::filament-magic-login.mail.subject', ['app' => config('app.name')]))
            ->greeting(__('filament-magic-login::filament-magic-login.mail.greeting'))
            ->line(__('filament-magic-login::filament-magic-login.mail.intro', [
                'duration' => ExpiryDuration::describe($this->expiresAfterMinutes),
            ]))
            ->action(__('filament-magic-login::filament-magic-login.mail.button'), $this->url)
            ->line(__('filament-magic-login::filament-magic-login.mail.ignore'))
            ->line(__('filament-magic-login::filament-magic-login.mail.fallback'))
            ->line($this->url);
    }
}
