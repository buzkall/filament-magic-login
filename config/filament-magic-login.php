<?php

use Arzcode\FilamentMagicLogin\Enums\MagicLinkPosition;
use Arzcode\FilamentMagicLogin\Notifications\MagicLinkNotification;

return [

    'storage' => [
        /*
         * 'database' adds the magic_login_tokens table and never touches the users table.
         * 'cache' needs no migration, but keeps no audit trail.
         */
        'driver' => env('FILAMENT_MAGIC_LOGIN_STORAGE', 'database'),

        'table' => 'magic_login_tokens',

        // Cache store used by the "cache" driver. Null uses the default store.
        'cache_store' => null,
    ],

    // Link lifetime, in minutes.
    'expires_after_minutes' => 15,

    // Where the action appears on the login page.
    'position' => MagicLinkPosition::BelowForm,

    // Icon on the login page's action. False removes it.
    'icon' => 'heroicon-o-envelope',

    // Rate limiting of link requests, keyed by email + IP.
    'rate_limit' => [
        'max_attempts' => 3,
        'decay_seconds' => 300,
    ],

    // Rate limiting of link consumption, keyed by IP.
    'consume_rate_limit' => [
        'max_attempts' => 10,
        'decay_seconds' => 60,
    ],

    // Links an administrator sends to a specific user from inside a panel.
    'admin' => [

        // Expiry pre-selected in the modal, in minutes. Null uses expires_after_minutes.
        'expires_after_minutes' => null,

        // Choices offered as toggle buttons. The effective default is always added, and
        // anything above max_expires_after_minutes is dropped.
        'expiry_presets' => [15, 60, 480, 1440, 4320],

        // Hard ceiling for the per-send override, in minutes. 4320 is three days: long
        // enough for a link sent on a Friday, short enough not to be a standing credential.
        'max_expires_after_minutes' => 4320,

        // Rate limiting of admin-issued links, keyed by the administrator. 0 disables it.
        'rate_limit' => [
            'max_attempts' => 10,
            'decay_seconds' => 60,
        ],

        // Gate ability checked against the target user before the action is shown or run.
        // Null defers to the resource's own authorization. See the README.
        'ability' => null,

        // Icon on the action and its modal. Null follows the icon above; false removes it.
        'icon' => null,

    ],

    // Invalidate the user's other unused tokens for the same panel when a new one is issued.
    'invalidate_previous' => true,

    // Must accept (string $url, int $expiresAfterMinutes, string $panelId) in its constructor.
    'notification' => MagicLinkNotification::class,

    // Queue the notification. Ignored when a custom notification class is configured.
    'queue' => true,

    // Route segment appended to the panel path: /{panel}/magic-login/{token}
    'route_path' => 'magic-login',

    // Forward the "remember me" checkbox state into the token.
    'honor_remember' => true,

    // Pad the response of unknown-email requests to blur timing differences.
    'blur_timing' => true,

];
