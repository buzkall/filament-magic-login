<?php

namespace Arzcode\FilamentMagicLogin\Tests\Fixtures\Panels;

use Arzcode\FilamentMagicLogin\MagicLoginPlugin;
use Closure;
use Filament\Panel;
use Filament\PanelProvider;

/**
 * Registered only by the tests that exercise custom login page detection.
 */
class CustomLoginPanelProvider extends PanelProvider
{
    /** @var class-string */
    public static string $loginPage = \Filament\Auth\Pages\Login::class;

    public static ?Closure $configurePlugin = null;

    public static bool $pluginBeforeLogin = false;

    public function panel(Panel $panel): Panel
    {
        $plugin = MagicLoginPlugin::make();

        if (static::$configurePlugin !== null) {
            $plugin = (static::$configurePlugin)($plugin);
        }

        $panel
            ->id('custom')
            ->path('custom')
            ->authGuard('web');

        // Both chain orders must behave identically.
        if (static::$pluginBeforeLogin) {
            return $panel->plugin($plugin)->login(static::$loginPage);
        }

        return $panel->login(static::$loginPage)->plugin($plugin);
    }
}
