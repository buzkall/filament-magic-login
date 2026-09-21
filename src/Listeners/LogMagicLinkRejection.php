<?php

namespace Arzcode\FilamentMagicLogin\Listeners;

use Arzcode\FilamentMagicLogin\Events\MagicLinkRejected;
use Illuminate\Support\Facades\Log;

/**
 * Writes every refusal to the log.
 *
 * The login page shows the same confirmation whether or not a link went out, which is
 * what keeps it from confirming an address exists — and also what makes "I never got
 * the email" impossible to answer from the outside. This line is the answer.
 */
final class LogMagicLinkRejection
{
    public function handle(MagicLinkRejected $event): void
    {
        if (! config('filament-magic-login.log_rejections.enabled', true)) {
            return;
        }

        $channel = config('filament-magic-login.log_rejections.channel');

        Log::channel(is_string($channel) ? $channel : null)->log(
            (string) config('filament-magic-login.log_rejections.level', 'info'),
            'filament-magic-login: link rejected ['.$event->reason.']',
            [
                'reason' => $event->reason,
                'email' => $event->email,
                'panel' => $event->panelId,
                'ip' => $event->ip,
            ],
        );
    }
}
