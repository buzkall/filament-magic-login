<?php

use Arzcode\FilamentMagicLogin\Actions\SendMagicLink;
use Arzcode\FilamentMagicLogin\Contracts\TokenRepository;
use Arzcode\FilamentMagicLogin\Notifications\MagicLinkNotification;
use Arzcode\FilamentMagicLogin\Notifications\QueuedMagicLinkNotification;
use Arzcode\FilamentMagicLogin\Tests\Fixtures\Models\User;
use Arzcode\FilamentMagicLogin\Tests\Fixtures\Notifications\CustomMagicLinkNotification;
use Arzcode\FilamentMagicLogin\Tests\TestCase;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

uses(TestCase::class)->in(__DIR__);

/**
 * @param  array<string, mixed>  $attributes
 */
function makeUser(array $attributes = []): User
{
    return User::query()->create([
        'name' => 'Test User',
        'email' => Str::random(12).'@example.com',
        'password' => 'password',
        'can_access' => true,
        ...$attributes,
    ]);
}

function requestLink(string $email, string $panelId = 'admin', bool $remember = false): void
{
    app(SendMagicLink::class)->handle(
        panel: Filament::getPanel($panelId),
        email: $email,
        remember: $remember,
        request: request(),
    );
}

function tokenCount(?User $user = null, string $panelId = 'admin'): int
{
    if ($user === null) {
        $user = User::query()->first();
    }

    if ($user === null) {
        return 0;
    }

    return count(app(TokenRepository::class)->unusedFor($user, $panelId));
}

/**
 * Calls a protected method on a Livewire page, so placement can be asserted
 * precisely instead of merely "the action exists somewhere".
 */
function callProtected(object $object, string $method): mixed
{
    $reflection = new ReflectionMethod($object, $method);

    return $reflection->invoke($object);
}

/**
 * @param  array<mixed>  $actions
 * @return array<int, string>
 */
function actionNames(array $actions): array
{
    return array_values(array_map(
        fn (object $action): string => $action->getName(),
        $actions,
    ));
}

/**
 * @return array<int, string>
 */
function hintActionNames(object $component): array
{
    if (! method_exists($component, 'getHintActions')) {
        return [];
    }

    return actionNames($component->getHintActions());
}

/**
 * Requests a link and returns the URL that was emailed.
 */
function magicLinkUrl(User $user, string $panelId = 'admin', bool $remember = false): string
{
    requestLink($user->email, $panelId, $remember);

    $classes = [
        QueuedMagicLinkNotification::class,
        MagicLinkNotification::class,
        CustomMagicLinkNotification::class,
    ];

    foreach ($classes as $class) {
        $sent = Notification::sent($user, $class);

        if ($sent->isNotEmpty()) {
            return $sent->last()->url;
        }
    }

    throw new RuntimeException('No magic link notification was sent to '.$user->email.'.');
}

/**
 * Bodies of the Filament notifications flashed to the session.
 *
 * @return array<int, string>
 */
function flashedNotificationBodies(): array
{
    return array_values(array_filter(array_map(
        fn (array $notification): ?string => $notification['body'] ?? null,
        session()->get('filament.notifications', []),
    )));
}
