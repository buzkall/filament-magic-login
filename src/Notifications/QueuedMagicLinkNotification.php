<?php

namespace Arzcode\FilamentMagicLogin\Notifications;

use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Used instead of MagicLinkNotification when the `queue` option is enabled.
 */
class QueuedMagicLinkNotification extends MagicLinkNotification implements ShouldQueue {}
