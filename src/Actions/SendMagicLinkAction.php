<?php

namespace Arzcode\FilamentMagicLogin\Actions;

use Arzcode\FilamentMagicLogin\Enums\MagicLinkDeliveryOutcome;
use Arzcode\FilamentMagicLogin\MagicLoginPlugin;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * Emails a login link to the user this action is attached to.
 *
 * One class serves all three placements — a table row action, and a header action on a
 * View or an Edit page — because Filament's own `getRecord()` falls back to the record
 * page's record when the action has none of its own.
 *
 * The link is only ever emailed to that user. It is never shown to the administrator
 * who sent it, which is what keeps this a way to help somebody log in rather than a way
 * to log in as them.
 */
class SendMagicLinkAction extends Action
{
    protected string|Closure|null $panelId = null;

    protected int|Closure|null $expiresAfterMinutes = null;

    protected int|Closure|null $maxExpiresAfterMinutes = null;

    /** @var array<int, int>|Closure|null */
    protected array|Closure|null $expiryPresets = null;

    protected bool|Closure $asksForExpiry = true;

    protected string|Closure|null $ability = null;

    public const CUSTOM = 'custom';

    public static function getDefaultName(): ?string
    {
        return 'sendMagicLink';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(fn (): string => __('filament-magic-login::filament-magic-login.admin.label'));
        $this->icon('heroicon-o-envelope');
        $this->color('gray');

        // Not requiresConfirmation(): that switches to the centred, warning-icon layout
        // meant for a bare yes/no, which reads badly around a field. These give the same
        // "confirm, and choose how long" modal in a shape that fits one.
        $this->modalHeading(fn (): string => __('filament-magic-login::filament-magic-login.admin.modal.heading'));
        $this->modalDescription(fn (): string => __('filament-magic-login::filament-magic-login.admin.modal.description', [
            'user' => $this->getRecipientLabel(),
        ]));
        $this->modalIcon('heroicon-o-envelope');
        $this->modalSubmitActionLabel(fn (): string => __('filament-magic-login::filament-magic-login.admin.modal.submit'));
        $this->modalWidth(Width::Medium);

        $this->schema(fn (): array => $this->shouldAskForExpiry() ? $this->getExpirySchema() : []);

        // hidden() rather than visible(), so an application setting its own ->visible()
        // condition adds to this guard instead of replacing it.
        $this->hidden(fn (): bool => $this->resolveRecipient() === null);

        $this->authorize(fn (): bool => $this->passesAbilityCheck());

        $this->action(function (array $data): void {
            $this->send($data);
        });
    }

    public function panel(string|Closure|null $panelId): static
    {
        $this->panelId = $panelId;

        return $this;
    }

    public function expiresAfter(int|Closure|null $minutes): static
    {
        $this->expiresAfterMinutes = $minutes;

        return $this;
    }

    public function maxExpiresAfter(int|Closure|null $minutes): static
    {
        $this->maxExpiresAfterMinutes = $minutes;

        return $this;
    }

    /**
     * @param  array<int, int>|Closure|null  $presets
     */
    public function expiryPresets(array|Closure|null $presets): static
    {
        $this->expiryPresets = $presets;

        return $this;
    }

    public function askForExpiry(bool|Closure $condition = true): static
    {
        $this->asksForExpiry = $condition;

        return $this;
    }

    public function ability(string|Closure|null $ability): static
    {
        $this->ability = $ability;

        return $this;
    }

    public function shouldAskForExpiry(): bool
    {
        return (bool) $this->evaluate($this->asksForExpiry);
    }

    public function getTargetPanel(): Panel
    {
        $id = $this->evaluate($this->panelId);

        return filled($id)
            ? Filament::getPanel((string) $id)
            : Filament::getCurrentOrDefaultPanel();
    }

    public function getDefaultExpiresAfterMinutes(): int
    {
        return (int) ($this->evaluate($this->expiresAfterMinutes)
            ?? $this->getPlugin()->getAdminExpiresAfterMinutes());
    }

    public function getMaximumExpiresAfterMinutes(): int
    {
        return (int) ($this->evaluate($this->maxExpiresAfterMinutes)
            ?? $this->getPlugin()->getMaxAdminExpiresAfterMinutes());
    }

    /**
     * The presets offered, in minutes: whatever is configured, plus the effective
     * default so the pre-selection is always one of the buttons, minus anything the
     * ceiling forbids.
     *
     * @return array<int, int>
     */
    public function getExpiryPresets(): array
    {
        /** @var array<int, mixed>|null $configured */
        $configured = $this->evaluate($this->expiryPresets);

        $presets = $configured === null
            ? $this->getPlugin()->getExpiryPresets()
            : array_map(intval(...), $configured);

        $presets[] = $this->getDefaultExpiresAfterMinutes();

        $maximum = $this->getMaximumExpiresAfterMinutes();

        $presets = array_filter($presets, fn (int $minutes): bool => $minutes >= 1 && $minutes <= $maximum);

        $presets = array_unique($presets);

        // sort() reindexes, so the result is already the list the signature promises.
        sort($presets);

        return $presets;
    }

    /**
     * The panel the link is minted for, which is not always the one the action renders
     * in: ->panel('app') is how an admin panel sends somebody a link for another panel.
     * Every setting below is read from that panel, never from the current one.
     */
    protected function getPlugin(): MagicLoginPlugin
    {
        $panel = $this->getTargetPanel();

        if (! $panel->hasPlugin(MagicLoginPlugin::ID)) {
            throw new LogicException(__('filament-magic-login::filament-magic-login.exceptions.panel_without_plugin', [
                'panel' => $panel->getId(),
            ]));
        }

        return MagicLoginPlugin::for($panel);
    }

    protected function resolveRecipient(): ?Authenticatable
    {
        $record = $this->getRecord();

        return $record instanceof Authenticatable ? $record : null;
    }

    protected function getRecipientLabel(): string
    {
        $record = $this->getRecord();

        if (is_object($record) && filled($record->email ?? null)) {
            return (string) $record->email;
        }

        return (string) ($this->getRecordTitle() ?? '');
    }

    protected function getAbility(): ?string
    {
        $ability = $this->evaluate($this->ability) ?? $this->getPlugin()->getAdminAbility();

        return filled($ability) ? (string) $ability : null;
    }

    /**
     * Allowed by default. Reaching a user's row or record page already means passing
     * the resource's own authorization, which is where an application that has opinions
     * about who may administer users has already expressed them. Defaulting to a policy
     * ability instead would fail closed everywhere, because Laravel's Gate denies an
     * ability no policy defines.
     */
    protected function passesAbilityCheck(): bool
    {
        $ability = $this->getAbility();

        if ($ability === null) {
            return true;
        }

        $user = $this->resolveRecipient();

        return $user !== null && Gate::allows($ability, $user);
    }

    /**
     * @return array<int, TextInput|ToggleButtons>
     */
    protected function getExpirySchema(): array
    {
        return [
            ToggleButtons::make('expires_preset')
                ->label(__('filament-magic-login::filament-magic-login.admin.field.expiry.label'))
                ->options(fn (): array => $this->getExpiryOptions())
                ->default(fn (): string => (string) $this->getDefaultExpiresAfterMinutes())
                ->required()
                ->grouped()
                ->live(),

            TextInput::make('expires_after_minutes')
                ->label(__('filament-magic-login::filament-magic-login.admin.field.custom.label'))
                ->helperText(fn (): string => __('filament-magic-login::filament-magic-login.admin.field.custom.helper', [
                    'max' => $this->getMaximumExpiresAfterMinutes(),
                ]))
                ->numeric()
                ->integer()
                ->required()
                ->minValue(1)
                ->maxValue(fn (): int => $this->getMaximumExpiresAfterMinutes())
                ->default(fn (): int => $this->getDefaultExpiresAfterMinutes())
                ->visible(fn (Get $get): bool => $get('expires_preset') === static::CUSTOM),
        ];
    }

    /**
     * The numeric choices and "custom" share one field. PHP casts the numeric keys back
     * to integers on the way into the array, which is why the values read back out of
     * the modal are compared loosely rather than against a string.
     *
     * @return array<array-key, string>
     */
    protected function getExpiryOptions(): array
    {
        $options = [];

        foreach ($this->getExpiryPresets() as $minutes) {
            $options[(string) $minutes] = $this->describeMinutes($minutes);
        }

        $options[static::CUSTOM] = __('filament-magic-login::filament-magic-login.admin.presets.custom');

        return $options;
    }

    protected function describeMinutes(int $minutes): string
    {
        [$unit, $count] = match (true) {
            $minutes % 1440 === 0 => ['days', intdiv($minutes, 1440)],
            $minutes % 60 === 0 => ['hours', intdiv($minutes, 60)],
            default => ['minutes', $minutes],
        };

        return trans_choice("filament-magic-login::filament-magic-login.admin.presets.{$unit}", $count, ['count' => $count]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function requestedMinutes(array $data): ?int
    {
        if (! $this->shouldAskForExpiry()) {
            return null;
        }

        $preset = $data['expires_preset'] ?? null;

        if ($preset === static::CUSTOM) {
            return (int) ($data['expires_after_minutes'] ?? $this->getDefaultExpiresAfterMinutes());
        }

        return filled($preset) ? (int) $preset : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function send(array $data): void
    {
        $user = $this->resolveRecipient();

        if ($user === null) {
            return;
        }

        $result = app(SendMagicLinkToUser::class)->handle(
            panel: $this->getTargetPanel(),
            user: $user,
            expiresAfterMinutes: $this->requestedMinutes($data),
            issuedBy: Filament::auth()->user(),
            request: request(),
        );

        $recipient = $this->getRecipientLabel();

        // One success and three distinct refusals, each of which the administrator needs
        // told apart — which is why this is not ->successNotification().
        match ($result->outcome) {
            MagicLinkDeliveryOutcome::Sent => Notification::make()
                ->title(__('filament-magic-login::filament-magic-login.admin.sent.title'))
                ->body(__('filament-magic-login::filament-magic-login.admin.sent.body', [
                    'user' => $recipient,
                    'minutes' => $result->expiresAfterMinutes,
                ]))
                ->success()
                ->send(),

            MagicLinkDeliveryOutcome::NoEmailAddress => Notification::make()
                ->title(__('filament-magic-login::filament-magic-login.admin.no_email.title'))
                ->body(__('filament-magic-login::filament-magic-login.admin.no_email.body', ['user' => $recipient]))
                ->danger()
                ->send(),

            MagicLinkDeliveryOutcome::CannotAccessPanel => Notification::make()
                ->title(__('filament-magic-login::filament-magic-login.admin.cannot_access.title'))
                ->body(__('filament-magic-login::filament-magic-login.admin.cannot_access.body', [
                    'user' => $recipient,
                    'panel' => $this->getTargetPanel()->getId(),
                ]))
                ->danger()
                ->send(),

            MagicLinkDeliveryOutcome::RateLimited => Notification::make()
                ->title(__('filament-magic-login::filament-magic-login.admin.rate_limited.title'))
                ->body(__('filament-magic-login::filament-magic-login.admin.rate_limited.body', [
                    'seconds' => $result->availableInSeconds ?? 0,
                ]))
                ->warning()
                ->send(),
        };
    }
}
