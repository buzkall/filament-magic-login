<?php

namespace Arzcode\FilamentMagicLogin\Http\Controllers;

use Arzcode\FilamentMagicLogin\Actions\ConsumeMagicLink;
use Arzcode\FilamentMagicLogin\Exceptions\InvalidMagicLinkException;
use Arzcode\FilamentMagicLogin\MagicLoginPlugin;
use Arzcode\FilamentMagicLogin\Support\Panels;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ConsumeMagicLinkController
{
    public function __invoke(Request $request, string $token): RedirectResponse|Response
    {
        // Mail scanners and link previewers issue HEAD requests; consuming a
        // single-use token for them would burn the link before the human clicks.
        if ($request->isMethod('HEAD')) {
            return response()->noContent();
        }

        $panel = Panels::current();
        $plugin = MagicLoginPlugin::for($panel);

        // Typically someone opening the same email a second time after the first click
        // signed them in. The token is left untouched: a link must never silently swap
        // an authenticated session for another account's.
        if ($panel->auth()->check()) {
            Notification::make()
                ->title(__('filament-magic-login::filament-magic-login.messages.already_signed_in_title'))
                ->body(__('filament-magic-login::filament-magic-login.messages.already_signed_in_body'))
                ->info()
                ->send();

            // getUrl() is null for a tenant panel whose user has no tenant yet; the panel
            // root then lets Filament decide, e.g. by showing tenant registration.
            return redirect()->to(
                $plugin->getRedirectUrl($panel->auth()->user()) ?? $panel->getUrl() ?? url($panel->getPath()),
            );
        }

        try {
            $user = app(ConsumeMagicLink::class)->handle($panel, $token, $request);
        } catch (InvalidMagicLinkException $exception) {
            Notification::make()
                ->title(__('filament-magic-login::filament-magic-login.messages.invalid_title'))
                ->body(__("filament-magic-login::filament-magic-login.messages.invalid_reason.{$exception->reason}"))
                ->danger()
                ->send();

            return redirect()->to($panel->getLoginUrl() ?? '/');
        }

        return redirect()->intended($plugin->getRedirectUrl($user) ?? $panel->getUrl());
    }
}
