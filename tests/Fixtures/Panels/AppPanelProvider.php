<?php

namespace Arzcode\FilamentMagicLogin\Tests\Fixtures\Panels;

use Arzcode\FilamentMagicLogin\MagicLoginPlugin;
use Closure;
use Filament\Panel;

class AppPanelProvider extends FixturePanelProvider
{
    public static ?Closure $configurePlugin = null;

    public function panel(Panel $panel): Panel
    {
        $plugin = MagicLoginPlugin::make();

        if (static::$configurePlugin !== null) {
            $plugin = (static::$configurePlugin)($plugin);
        }

        return $panel
            ->id('app')
            ->path('app')
            ->login()
            ->authGuard('web')
            ->middleware($this->defaultMiddleware())
            ->plugin($plugin);
    }
}
