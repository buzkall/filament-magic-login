<?php

namespace Arzcode\FilamentMagicLogin\Support;

use Filament\Facades\Filament;
use Filament\Panel;
use LogicException;

/**
 * Filament's panel lookups are typed as nullable, but the current-or-default one
 * throws rather than return null, and a panel named in configuration that does not
 * exist is a mistake worth saying out loud instead of failing on a null later.
 */
final class Panels
{
    public static function current(): Panel
    {
        return Filament::getCurrentPanel() ?? Filament::getDefaultPanel();
    }

    public static function find(string $id): Panel
    {
        return Filament::getPanels()[$id]
            ?? throw new LogicException(__('filament-magic-login::filament-magic-login.exceptions.unknown_panel', [
                'panel' => $id,
            ]));
    }
}
