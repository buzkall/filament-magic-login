<?php

namespace Arzcode\FilamentMagicLogin\Concerns;

use Arzcode\FilamentMagicLogin\Actions\SendMagicLink;
use Arzcode\FilamentMagicLogin\Enums\MagicLinkPosition;
use Arzcode\FilamentMagicLogin\MagicLoginPlugin;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Form;

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
            ->tooltip(fn (): string => $this->getMagicLoginPlugin()->getTooltip())
            ->icon('heroicon-o-envelope')
            ->color('gray')
            ->action(function (): void {
                $this->sendMagicLink();
            });

        return $plugin->getPosition() === MagicLinkPosition::EmailFieldHint
            ? $action->iconButton()
            : $action->link();
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
                'minutes' => $this->getMagicLoginPlugin()->getExpiresAfterMinutes(),
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
     * beside it, by hanging it off the form actions container.
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

        return $component->footer([
            Actions::make($this->getFormActions())
                ->alignment($this->getFormActionsAlignment())
                ->fullWidth($this->hasFullWidthFormActions())
                ->key('form-actions')
                ->belowContent(
                    Actions::make([$this->magicLinkAction()])
                        ->key('magic-login-actions'),
                ),
        ]);
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
