<?php

namespace Arzcode\FilamentMagicLogin\Concerns;

use Arzcode\FilamentMagicLogin\Actions\SendMagicLink;
use Arzcode\FilamentMagicLogin\Enums\MagicLinkPosition;
use Arzcode\FilamentMagicLogin\MagicLoginPlugin;
use Arzcode\FilamentMagicLogin\Support\ExpiryDuration;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Html;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Adds the "email me a login link" action to a Filament login page.
 *
 * The action lives on the login page itself so it can read the email address the
 * visitor already typed, without validating (and therefore requiring) the password.
 */
trait HasMagicLinkAction
{
    public function magicLinkAction(): Action
    {
        $plugin = $this->getMagicLoginPlugin();

        $action = Action::make('magicLink')
            ->label(fn (): string => $this->getMagicLoginPlugin()->getLabel())
            ->icon(fn (): string|BackedEnum|Htmlable|null => $this->getMagicLoginPlugin()->getIcon())
            ->color('gray')
            // Password managers that sign you in after filling pick a button out of the
            // login form and click it, ignoring `type="submit"`; a button labelled "email
            // me a login link" reads to them like a second way in, so a mere autofill used
            // to send a link nobody asked for. 1Password documents that it clicks with
            // `element.click()`, and a click no hand made carries `isTrusted: false` — so
            // the action is mounted from Alpine, behind that check, instead of from
            // `wire:click`. A real click, and Enter or Space on the focused button, are
            // trusted and pass straight through.
            ->alpineClickHandler("\$event.isTrusted && \$wire.mountAction('magicLink')")
            // Restores the spinner the replaced `wire:click` used to provide.
            ->livewireTarget('mountAction')
            // Belt and braces, for the managers that read them.
            ->extraAttributes([
                'data-1p-ignore' => 'true',
                'data-lpignore' => 'true',
                'data-bwignore' => 'true',
                'data-form-type' => 'other',
            ])
            ->action(function (): void {
                $this->sendMagicLink();
            });

        // Below the form it mirrors the "Sign in" button — same shape and size, but
        // uncolored, so it reads as the secondary way in. On the email field it is a
        // labeled link, which fits the hint row.
        return $plugin->getPosition() === MagicLinkPosition::EmailFieldHint
            ? $action->link()
            : $action->button();
    }

    protected function sendMagicLink(): void
    {
        $email = $this->getMagicLinkEmail();

        if (blank($email)) {
            $this->addError('data.email', __('filament-magic-login::filament-magic-login.messages.email_required'));

            return;
        }

        $panel = Filament::getCurrentOrDefaultPanel();

        app(SendMagicLink::class)->handle(
            panel: $panel,
            email: $email,
            remember: (bool) ($this->getMagicLinkFormState()['remember'] ?? false),
            request: request(),
        );

        Notification::make()
            ->title(__('filament-magic-login::filament-magic-login.messages.sent_title'))
            ->body(__('filament-magic-login::filament-magic-login.messages.sent_body', [
                'duration' => ExpiryDuration::describe($this->getMagicLoginPlugin()->getExpiresAfterMinutes()),
            ]))
            ->success()
            ->send();
    }

    /**
     * Deliberately reads raw state: validating the whole form here would demand a
     * password the visitor does not intend to type.
     */
    protected function getMagicLinkEmail(): ?string
    {
        $email = trim((string) ($this->getMagicLinkFormState()['email'] ?? ''));

        return $email === '' ? null : $email;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getMagicLinkFormState(): array
    {
        $state = $this->form->getRawState();

        return is_array($state) ? $state : (array) $state;
    }

    protected function getMagicLoginPlugin(): MagicLoginPlugin
    {
        return MagicLoginPlugin::for(Filament::getCurrentOrDefaultPanel());
    }

    /**
     * Places the action on its own row underneath the "Sign in" button, rather than
     * beside it, by wrapping the form and stacking the button after it.
     *
     * The wrapper keeps the button *outside* the `<form>` element on purpose: password
     * managers that fill and submit look for something to click inside the form that owns
     * the fields they just filled, and this button used to sit right there. Livewire holds
     * the typed address in the component state, so the action still reads it from out here.
     */
    public function getFormContentComponent(): Component
    {
        $component = parent::getFormContentComponent();

        if ($this->getMagicLoginPlugin()->getPosition() !== MagicLinkPosition::BelowForm) {
            return $component;
        }

        if (! ($component instanceof Form)) {
            return $component;
        }

        return Group::make([
            $component,

            $this->magicLinkSeparator(),

            Actions::make([$this->magicLinkAction()])
                ->alignment($this->getFormActionsAlignment())
                ->fullWidth($this->hasFullWidthFormActions())
                ->key('magic-login-actions'),
        ])
            // Mirrors the form's own visibility, so the multi-factor challenge is not
            // shown alongside a button offering to skip it.
            ->visible(fn (): bool => $component->isVisible())
            ->key('magic-login-group');
    }

    /**
     * A rule with "or" set in the middle of it, telling the two buttons apart.
     *
     * The styles are inline and lean on currentColor: panels ship Filament's compiled
     * stylesheet, which carries no utility classes for a package view to borrow, and
     * currentColor spares us a dark-mode variant.
     */
    protected function magicLinkSeparator(): Component
    {
        $rule = '<span style="flex: 1 1 0%; height: 1px; background-color: currentColor; opacity: 0.15;"></span>';

        return Html::make(
            '<div data-magic-login-separator style="display: flex; align-items: center; gap: 0.75rem;" aria-hidden="true">'
            .$rule
            .'<span style="font-size: 0.875rem; line-height: 1.25rem; opacity: 0.6;">'
            .e(__('filament-magic-login::filament-magic-login.actions.or'))
            .'</span>'
            .$rule
            .'</div>'
        )->key('magic-login-separator');
    }

    protected function getEmailFormComponent(): Component
    {
        $component = parent::getEmailFormComponent();

        if ($this->getMagicLoginPlugin()->getPosition() !== MagicLinkPosition::EmailFieldHint) {
            return $component;
        }

        if (! ($component instanceof TextInput)) {
            return $component;
        }

        return $component->hintAction($this->magicLinkAction());
    }
}
