<?php

use Arzcode\FilamentMagicLogin\MagicLoginPlugin;
use Filament\Facades\Filament;

it('boots the admin panel with the plugin registered', function (): void {
    $panel = Filament::getPanel('admin');

    expect($panel->hasPlugin(MagicLoginPlugin::ID))->toBeTrue();
});
