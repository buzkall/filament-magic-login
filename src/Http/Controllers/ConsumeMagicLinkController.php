<?php

namespace Arzcode\FilamentMagicLogin\Http\Controllers;

use Arzcode\FilamentMagicLogin\Actions\ConsumeMagicLink;
use Arzcode\FilamentMagicLogin\Exceptions\InvalidMagicLinkException;
use Arzcode\FilamentMagicLogin\MagicLoginPlugin;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ConsumeMagicLinkController
{
    public function __invoke(Request $request, string $token): RedirectResponse | Response
    {
        // Mail scanners and link previewers issue HEAD requests; consuming a
        // single-use token for them would burn the link before the human clicks.
        if ($request->isMethod('HEAD')) {
            return response()->noContent();
        }

        $panel = Filament::getCurrentOrDefaultPanel();
        $plugin = MagicLoginPlugin::for($panel);

        try {
            $user = app(ConsumeMagicLink::class)->handle($panel, $token, $request);
        } catch (InvalidMagicLinkException $exception) {
            Notification::make()
                ->title(__('filament-magic-login::filament-magic-login.messages.invalid_title'))
                ->body(__("filament-magic-login::filament-magic-login.messages.invalid_reason.{$exception->reason}"))
                ->danger()
                ->send();

            return redirect()->to($panel->getLoginUrl());
        }

        return redirect()->intended($plugin->getRedirectUrl($user) ?? $panel->getUrl());
    }
}
