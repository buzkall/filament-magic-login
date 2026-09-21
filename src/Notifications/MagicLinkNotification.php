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

    /**
     * Not readonly: before PHP 8.4 a readonly property can only be initialised from
     * the class declaring it, so unserializing QueuedMagicLinkNotification off the
     * queue would fail.
     */
    public function __construct(
        public string $url,
        public int $expiresAfterMinutes,
        public string $panelId,
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
            ->line(__('filament-magic-login::filament-magic-login.mail.ignore'));
    }
}
