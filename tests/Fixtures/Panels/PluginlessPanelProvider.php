<?php

namespace Arzcode\FilamentMagicLogin\Tests\Fixtures\Panels;

use Filament\Panel;

/**
 * A panel that never registers the plugin, so the guard against pointing an action at
 * one can be exercised for real rather than against a panel that does not exist.
 */
class PluginlessPanelProvider extends FixturePanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('bare')
            ->path('bare')
            ->login()
            ->authGuard('web')
            ->middleware($this->defaultMiddleware());
    }
}
