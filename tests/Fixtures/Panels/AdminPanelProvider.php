<?php

namespace Arzcode\FilamentMagicLogin\Tests\Fixtures\Panels;

use Arzcode\FilamentMagicLogin\MagicLoginPlugin;
use Closure;
use Filament\Http\Middleware\Authenticate;
use Filament\Pages\Dashboard;
use Filament\Panel;

class AdminPanelProvider extends FixturePanelProvider
{
    public static ?Closure $configurePlugin = null;

    public static ?Closure $configurePanel = null;

    public function panel(Panel $panel): Panel
    {
        $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->authGuard('web')
            ->middleware($this->defaultMiddleware())
            ->authMiddleware([Authenticate::class])
            ->pages([Dashboard::class])
            ->plugin($this->plugin());

        if (static::$configurePanel !== null) {
            $panel = (static::$configurePanel)($panel);
        }

        return $panel;
    }

    protected function plugin(): MagicLoginPlugin
    {
        $plugin = MagicLoginPlugin::make();

        if (static::$configurePlugin !== null) {
            $plugin = (static::$configurePlugin)($plugin);
        }

        return $plugin;
    }
}
