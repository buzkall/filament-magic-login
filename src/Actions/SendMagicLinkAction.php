<?php

namespace Arzcode\FilamentMagicLogin\Actions;

use Arzcode\FilamentMagicLogin\Enums\MagicLinkDeliveryOutcome;
use Arzcode\FilamentMagicLogin\MagicLoginPlugin;
use Arzcode\FilamentMagicLogin\Support\ExpiryDuration;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Models\Contracts\FilamentUser;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Htmlable;
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

    /** @var array<int, string>|Closure|null */
    protected array|Closure|null $panelIds = null;

    protected bool|Closure $usesAnyPanel = false;

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
        $this->icon(fn (SendMagicLinkAction $action): string|BackedEnum|Htmlable|null => $action->getPlugin()->getAdminIcon());
        $this->color('gray');

        // In a table row the action shows as an icon button (see getView()), which has no
        // label to read, so give it the label as a hover tooltip. Page-header actions keep
        // their label and need none.
        $this->tooltip(fn (SendMagicLinkAction $action): ?string => $action->isInTable() ? $action->getLabel() : null);

        // Not requiresConfirmation(): that switches to the centred, warning-icon layout
        // meant for a bare yes/no, which reads badly around a field. These give the same
        // "confirm, and choose how long" modal in a shape that fits one.
        $this->modalHeading(fn (): string => __('filament-magic-login::filament-magic-login.admin.modal.heading'));
        $this->modalDescription(fn (SendMagicLinkAction $action): string => $action->describeSend());
        $this->modalIcon(fn (SendMagicLinkAction $action): string|BackedEnum|Htmlable|null => $action->getPlugin()->getAdminIcon());
        $this->modalSubmitActionLabel(fn (): string => __('filament-magic-login::filament-magic-login.admin.modal.submit'));
        $this->modalWidth(Width::Large);

        $this->schema(fn (SendMagicLinkAction $action): array => $action->shouldAskForExpiry() ? $action->getExpirySchema() : []);

        // hidden() rather than visible(), so an application setting its own ->visible()
        // condition adds to this guard instead of replacing it.
        //
        // Every closure below takes the action as a parameter rather than closing over
        // $this. A table clones the action once per row and only the clone is given the
        // row's record, while a closure built here stays bound to the original, whose
        // record is forever null — which would hide the action from every row.
        $this->hidden(fn (SendMagicLinkAction $action): bool => ! $action->canReceiveMagicLink());

        $this->authorize(fn (SendMagicLinkAction $action): bool => $action->passesAbilityCheck());

        $this->action(function (SendMagicLinkAction $action, array $data): void {
            $action->send($data);
        });
    }

    /**
     * A table row shows a compact icon button; a page-header action keeps its label.
     * One class serves both, so the style is chosen by context here rather than fixed in
     * setUp() — where the table is not yet bound. An application that picks a style
     * explicitly (`->button()`, `->iconButton()`, `->link()`) still wins, because that
     * sets `$this->view`.
     */
    public function getView(): string
    {
        if (! isset($this->view) && $this->isInTable()) {
            return static::ICON_BUTTON_VIEW;
        }

        return parent::getView();
    }

    /**
     * Whatever the icon turns out to be — ours, or a name an application handed to
     * Filament's own `->icon()` — is checked before it reaches the renderer. Blade Icons
     * throws SvgNotFound for a name no set has, and an icon in a table row is drawn
     * inside the page: a typo there is a 500 on the whole users table rather than a
     * missing glyph. Falling back to the panel's own icon keeps a typo cosmetic.
     */
    public function getIcon(string|BackedEnum|Htmlable|null $default = null): string|BackedEnum|Htmlable|null
    {
        return $this->rescueIcon(parent::getIcon($default));
    }

    public function getModalIcon(): string|BackedEnum|Htmlable|null
    {
        return $this->rescueIcon(parent::getModalIcon());
    }

    protected function rescueIcon(string|BackedEnum|Htmlable|null $icon): string|BackedEnum|Htmlable|null
    {
        return $this->getPlugin()->resolveIcon(
            $icon,
            fn (): string|BackedEnum|Htmlable|null => $this->getPlugin()->getAdminIcon(),
        );
    }

    /**
     * Not hasTable(): that only arrived in a later 5.x, and the package supports ^5.0.
     */
    public function isInTable(): bool
    {
        return $this->getTable() !== null;
    }

    public function panel(string|Closure|null $panelId): static
    {
        $this->panelId = $panelId;

        return $this;
    }

    /**
     * Panels to consider, most preferred first: the link is minted for the first one the
     * user can actually reach. A contractor who is only in your `app` panel gets an app
     * link from the admin panel's users table, without you having to know which of them
     * that is per row.
     *
     * @param  array<int, string>|Closure|null  $panelIds
     */
    public function panels(array|Closure|null $panelIds): static
    {
        $this->panelIds = $panelIds;

        return $this;
    }

    /**
     * The same, over every panel that registers the plugin — the current one first, so a
     * user who can reach both is sent where the administrator is standing.
     */
    public function anyPanel(bool|Closure $condition = true): static
    {
        $this->usesAnyPanel = $condition;

        return $this;
    }

    public function usesAnyPanel(): bool
    {
        return (bool) $this->evaluate($this->usesAnyPanel);
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

    /**
     * The panels this action will consider, most preferred first. One, unless the
     * application named more.
     *
     * @return array<int, Panel>
     */
    public function getCandidatePanels(): array
    {
        $id = $this->evaluate($this->panelId);

        if (filled($id)) {
            return [Filament::getPanel((string) $id)];
        }

        /** @var array<int, mixed>|null $ids */
        $ids = $this->evaluate($this->panelIds);

        if (filled($ids)) {
            return array_values(array_map(
                fn (mixed $id): Panel => Filament::getPanel((string) $id),
                $ids,
            ));
        }

        if ($this->usesAnyPanel()) {
            return $this->getPanelsWithThePlugin();
        }

        return [Filament::getCurrentOrDefaultPanel()];
    }

    /**
     * The panel the link is minted for, which is not always the one the action renders
     * in: ->panel('app') is how an admin panel sends somebody a link for another panel,
     * and ->panels() / ->anyPanel() let the recipient's own access decide which.
     *
     * Falls back to the first candidate when none will have them, so the settings and
     * the modal still resolve for an action that is hidden anyway.
     */
    public function getTargetPanel(): Panel
    {
        $candidates = $this->getCandidatePanels();

        $user = $this->resolveRecipient();

        foreach ($candidates as $panel) {
            if ($user === null || $this->admits($panel, $user)) {
                return $panel;
            }
        }

        return $candidates[0] ?? Filament::getCurrentOrDefaultPanel();
    }

    /**
     * Every panel that could mint a link, the current one first. Panels without the
     * plugin are skipped rather than thrown over: this list is inferred, not named.
     *
     * @return array<int, Panel>
     */
    protected function getPanelsWithThePlugin(): array
    {
        $current = Filament::getCurrentOrDefaultPanel();

        $panels = array_filter(
            Filament::getPanels(),
            fn (Panel $panel): bool => $panel->getId() !== $current->getId()
                && $panel->hasPlugin(MagicLoginPlugin::ID),
        );

        return [
            ...($current->hasPlugin(MagicLoginPlugin::ID) ? [$current] : []),
            ...array_values($panels),
        ];
    }

    protected function admits(Panel $panel, Authenticatable $user): bool
    {
        return (! $user instanceof FilamentUser) || $user->canAccessPanel($panel);
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
     * Every setting is read from the panel the link is minted for, never from the
     * current one.
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

    /**
     * Whether a link sent from here could actually let this record in: it has to be
     * something that can be authenticated, it must not be the administrator sending it,
     * and the *target* panel — the one named by ->panel(), not necessarily the one being
     * looked at — has to admit it.
     *
     * A user the panel would turn away is hidden rather than refused on submit, because
     * the answer is known while the row is being drawn and there is nothing the
     * administrator could do about it. The refusal in SendMagicLinkToUser stays as the
     * backstop for every other caller, and for access revoked after the page was drawn.
     */
    public function canReceiveMagicLink(): bool
    {
        $user = $this->resolveRecipient();

        if ($user === null) {
            return false;
        }

        if ($this->isRecipientTheCurrentUser()) {
            return false;
        }

        // getTargetPanel() already answers with a panel that admits them where there is
        // one, so this is only ever false when no candidate would.
        return $this->admits($this->getTargetPanel(), $user);
    }

    /**
     * Somebody already signed in has no use for a link into their own inbox to get where
     * they already are, so their own row does not offer one.
     */
    protected function isRecipientTheCurrentUser(): bool
    {
        $user = $this->resolveRecipient();
        $current = Filament::auth()->user();

        if ($user === null || $current === null) {
            return false;
        }

        // Loosely on the identifier, and only within the same class: a key read back
        // from a route parameter arrives as a string where the signed-in user's is an
        // integer, and PHP 8 no longer compares those two the surprising way.
        return $user::class === $current::class
            && $user->getAuthIdentifier() == $current->getAuthIdentifier();
    }

    protected function resolveRecipient(): ?Authenticatable
    {
        $record = $this->getRecord();

        return $record instanceof Authenticatable ? $record : null;
    }

    /**
     * Names the panel whenever it is not the one being looked at, because with
     * ->panels() the answer is the recipient's to decide and differs row by row.
     */
    protected function describeSend(): string
    {
        $description = __('filament-magic-login::filament-magic-login.admin.modal.description', [
            'user' => $this->getRecipientLabel(),
        ]);

        $panel = $this->getTargetPanel();

        if ($panel->getId() === Filament::getCurrentOrDefaultPanel()->getId()) {
            return $description;
        }

        return $description.' '.__('filament-magic-login::filament-magic-login.admin.modal.panel', [
            'panel' => $panel->getId(),
        ]);
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
                    'duration' => ExpiryDuration::describe($result->expiresAfterMinutes),
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
