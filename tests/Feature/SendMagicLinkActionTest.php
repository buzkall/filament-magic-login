<?php

use Arzcode\FilamentMagicLogin\Actions\SendMagicLinkAction;
use Arzcode\FilamentMagicLogin\Contracts\TokenRepository;
use Arzcode\FilamentMagicLogin\Tests\Fixtures\Filament\Resources\Users\Pages\EditUser;
use Arzcode\FilamentMagicLogin\Tests\Fixtures\Filament\Resources\Users\Pages\ListUsers;
use Arzcode\FilamentMagicLogin\Tests\Fixtures\Filament\Resources\Users\Pages\ViewUser;
use Arzcode\FilamentMagicLogin\Tests\Fixtures\Filament\Resources\Users\Tables\UsersTable;
use Arzcode\FilamentMagicLogin\Tests\Fixtures\Filament\Resources\Users\UserResource;
use Arzcode\FilamentMagicLogin\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Notification;

use function Pest\Livewire\livewire;

/**
 * Registers the fixture user resource on the admin panel and signs an administrator in,
 * which is the state every one of these assertions starts from.
 */
function withUserResource(?Closure $configurePlugin = null): void
{
    test()->rebootWith(
        [],
        $configurePlugin,
        fn ($panel) => $panel->resources([UserResource::class]),
    );

    Notification::fake();

    test()->actingAs(makeUser(['name' => 'Administrator']), 'web');
}

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    Notification::fake();
});

it('names itself so an application does not have to', function (): void {
    expect(SendMagicLinkAction::make()->getName())->toBe('sendMagicLink');
});

it('renders as an icon button in a table row', function (): void {
    withUserResource();

    $action = livewire(ListUsers::class)->instance()->getTable()->getAction('sendMagicLink');

    expect($action->isIconButton())->toBeTrue()
        // The icon has no label of its own, so the label carries over as a tooltip.
        ->and($action->getTooltip())->toBe($action->getLabel());
});

it('lets an application override the row style', function (): void {
    withUserResource();

    UsersTable::$configureAction = fn (SendMagicLinkAction $action) => $action->button();

    $action = livewire(ListUsers::class)->instance()->getTable()->getAction('sendMagicLink');

    expect($action->isButton())->toBeTrue()
        ->and($action->isIconButton())->toBeFalse();
});

it('keeps its label away from a table — as a page-header button, and standalone', function (): void {
    // No table bound: the default labelled button, with no redundant tooltip.
    $action = SendMagicLinkAction::make();

    expect($action->isButton())->toBeTrue()
        ->and($action->isIconButton())->toBeFalse()
        ->and($action->getTooltip())->toBeNull()
        // An explicit choice still wins even with no table.
        ->and(SendMagicLinkAction::make()->iconButton()->isIconButton())->toBeTrue();
});

it('opens a wide modal', function (): void {
    expect(SendMagicLinkAction::make()->getModalWidth())->toBe(Width::Large);
});

it('sends a link from the table row action', function (): void {
    withUserResource();

    $target = makeUser();

    livewire(ListUsers::class)
        ->callAction(TestAction::make('sendMagicLink')->table($target), [
            'expires_preset' => '60',
        ])
        ->assertHasNoActionErrors();

    expect(lastMagicLinkNotification($target)?->expiresAfterMinutes)->toBe(60)
        ->and(tokenCount($target))->toBe(1);
});

it('sends a link from the header action on the view page', function (): void {
    withUserResource();

    $target = makeUser();

    livewire(ViewUser::class, ['record' => $target->getKey()])
        ->callAction('sendMagicLink', ['expires_preset' => '1440'])
        ->assertHasNoActionErrors();

    expect(lastMagicLinkNotification($target)?->expiresAfterMinutes)->toBe(1440);
});

it('sends a link from the header action on the edit page', function (): void {
    withUserResource();

    $target = makeUser();

    livewire(EditUser::class, ['record' => $target->getKey()])
        ->callAction('sendMagicLink', ['expires_preset' => '4320'])
        ->assertHasNoActionErrors();

    expect(lastMagicLinkNotification($target)?->expiresAfterMinutes)->toBe(4320);
});

it('uses the custom field when the custom preset is chosen', function (): void {
    withUserResource();

    $target = makeUser();

    livewire(ViewUser::class, ['record' => $target->getKey()])
        ->callAction('sendMagicLink', [
            'expires_preset' => SendMagicLinkAction::CUSTOM,
            'expires_after_minutes' => 37,
        ])
        ->assertHasNoActionErrors();

    expect(lastMagicLinkNotification($target)?->expiresAfterMinutes)->toBe(37);
});

it('renders the presets as toggle buttons, revealing the custom field only when asked', function (): void {
    withUserResource();

    $component = livewire(ViewUser::class, ['record' => makeUser()->getKey()])
        ->mountAction('sendMagicLink')
        ->assertActionMounted('sendMagicLink')
        // The panel's own lifetime is pre-selected, so submitting straight away is safe.
        ->assertActionDataSet(['expires_preset' => '15'])
        ->assertSchemaComponentVisible('expires_preset', 'mountedActionSchema0')
        ->assertSchemaComponentHidden('expires_after_minutes', 'mountedActionSchema0');

    // Set through Livewire rather than fillForm(), which disables the state-update
    // hooks the ->live() toggle relies on.
    $component
        ->set('mountedActions.0.data.expires_preset', SendMagicLinkAction::CUSTOM)
        ->assertSchemaComponentVisible('expires_after_minutes', 'mountedActionSchema0');
});

it('rejects a custom expiry outside the allowed range', function (int $minutes): void {
    withUserResource();

    $target = makeUser();

    livewire(ViewUser::class, ['record' => $target->getKey()])
        ->callAction('sendMagicLink', [
            'expires_preset' => SendMagicLinkAction::CUSTOM,
            'expires_after_minutes' => $minutes,
        ])
        ->assertHasActionErrors(['expires_after_minutes']);

    expect(tokenCount($target))->toBe(0);
})->with([0, 4321]);

it('offers the configured presets plus a custom choice', function (): void {
    withUserResource();

    $options = callProtected(SendMagicLinkAction::make(), 'getExpiryOptions');

    // PHP casts the numeric keys to integers on the way in; only "custom" stays a string.
    expect(array_keys($options))->toBe([15, 60, 480, 1440, 4320, 'custom'])
        ->and($options[60])->toBe('1 hour')
        ->and($options[1440])->toBe('1 day')
        ->and($options[4320])->toBe('3 days')
        ->and($options[15])->toBe('15 minutes');
});

it('always offers the effective default, and drops presets above the ceiling', function (): void {
    withUserResource(fn ($plugin) => $plugin->adminExpiresAfter(37)->maxAdminExpiresAfter(120));

    $options = callProtected(SendMagicLinkAction::make(), 'getExpiryOptions');

    expect(array_keys($options))->toBe([15, 37, 60, 'custom']);
});

it('lets the action override the plugin defaults', function (): void {
    withUserResource();

    $action = SendMagicLinkAction::make()
        ->expiresAfter(90)
        ->maxExpiresAfter(200)
        ->expiryPresets([30, 90, 500]);

    expect($action->getDefaultExpiresAfterMinutes())->toBe(90)
        ->and($action->getMaximumExpiresAfterMinutes())->toBe(200)
        // 500 is above the ceiling and drops out; 90 is the default and stays.
        ->and($action->getExpiryPresets())->toBe([30, 90]);
});

it('skips the expiry question when asked to', function (): void {
    withUserResource(fn ($plugin) => $plugin->adminExpiresAfter(25));

    UsersTable::$configureAction = fn (SendMagicLinkAction $action) => $action->askForExpiry(false);

    $target = makeUser();

    livewire(ListUsers::class)
        ->callAction(TestAction::make('sendMagicLink')->table($target))
        ->assertHasNoActionErrors();

    expect(lastMagicLinkNotification($target)?->expiresAfterMinutes)->toBe(25);
});

it('mints the link for another panel when one is named', function (): void {
    withUserResource();

    ViewUser::$configureAction = fn (SendMagicLinkAction $action) => $action->panel('app');

    $target = makeUser();

    livewire(ViewUser::class, ['record' => $target->getKey()])
        ->callAction('sendMagicLink', ['expires_preset' => '60'])
        ->assertHasNoActionErrors();

    expect(app(TokenRepository::class)->unusedFor($target, 'app'))->toHaveCount(1)
        ->and(app(TokenRepository::class)->unusedFor($target, 'admin'))->toBeEmpty()
        ->and(lastMagicLinkNotification($target)?->url)
        ->toContain(Filament::getPanel('app')->getPath());
});

it('refuses to read settings from a panel that does not register the plugin', function (): void {
    TestCase::$registerPluginlessPanel = true;

    withUserResource();

    $action = SendMagicLinkAction::make()->panel('bare');

    expect(fn (): int => $action->getDefaultExpiresAfterMinutes())
        ->toThrow(
            LogicException::class,
            __('filament-magic-login::filament-magic-login.exceptions.panel_without_plugin', ['panel' => 'bare']),
        );
});

it('tells the administrator what happened, in every outcome', function (Closure $target, string $key, array $replace, string $status): void {
    withUserResource();

    $user = $target();

    livewire(ViewUser::class, ['record' => $user->getKey()])
        ->callAction('sendMagicLink', ['expires_preset' => '60'])
        ->assertNotified(
            FilamentNotification::make()
                ->title(__("filament-magic-login::filament-magic-login.admin.{$key}.title"))
                ->body(__("filament-magic-login::filament-magic-login.admin.{$key}.body", [
                    // The action names the recipient by address, falling back to the
                    // record title when there is none — which is the whole point of the
                    // "no email address" case.
                    'user' => $user->email ?: $user->name,
                    ...$replace,
                ]))
                ->{$status}(),
        );
})->with([
    // An hour stays in minutes; only a longer link is cascaded into hours and days.
    'sent' => [fn () => makeUser(), 'sent', ['duration' => '60 minutes'], 'success'],
    'no email' => [fn () => makeUser(['email' => '', 'name' => 'Nameless Norah']), 'no_email', [], 'danger'],
    'cannot access' => [fn () => makeUser(['can_access' => false]), 'cannot_access', ['panel' => 'admin'], 'danger'],
]);

it('warns the administrator once they have sent too many links', function (): void {
    withUserResource(fn ($plugin) => $plugin->adminRateLimit(maxAttempts: 1, decaySeconds: 300));

    $target = makeUser();

    $component = livewire(ViewUser::class, ['record' => $target->getKey()]);

    $component->callAction('sendMagicLink', ['expires_preset' => '60']);
    $component
        ->callAction('sendMagicLink', ['expires_preset' => '60'])
        ->assertNotified(__('filament-magic-login::filament-magic-login.admin.rate_limited.title'));

    // The refused send left no second token behind.
    expect(tokenCount($target))->toBe(1);
});

it('can be hidden by an application without losing its own record guard', function (): void {
    withUserResource();

    UsersTable::$configureAction = fn (SendMagicLinkAction $action) => $action->visible(false);

    $target = makeUser();

    livewire(ListUsers::class)
        ->assertActionHidden(TestAction::make('sendMagicLink')->table($target));
});

it('renders on every row, where the table hands each clone its own record', function (): void {
    withUserResource();

    $target = makeUser();

    // A table clones the action once per row and gives the record to the clone alone, so
    // a guard closing over $this reads the original's forever-null record and hides the
    // action from every row — while still passing an assertion that resolves it by name.
    $clone = SendMagicLinkAction::make()->getClone();
    $clone->record($target);

    expect($clone->isHidden())->toBeFalse();

    livewire(ListUsers::class)
        ->assertSeeHtml("mountAction('sendMagicLink'");
});

it('is visible by default, and hidden when a configured ability denies it', function (): void {
    withUserResource();

    $target = makeUser();

    livewire(ListUsers::class)
        ->assertActionVisible(TestAction::make('sendMagicLink')->table($target));

    UsersTable::$configureAction = fn (SendMagicLinkAction $action) => $action->ability('never-granted');

    livewire(ListUsers::class)
        ->assertActionHidden(TestAction::make('sendMagicLink')->table($target));
});
