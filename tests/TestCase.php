<?php

namespace Arzcode\FilamentMagicLogin\Tests;

use Arzcode\FilamentMagicLogin\FilamentMagicLoginServiceProvider;
use Arzcode\FilamentMagicLogin\Tests\Fixtures\Models\User;
use Arzcode\FilamentMagicLogin\Tests\Fixtures\Panels\AdminPanelProvider;
use Arzcode\FilamentMagicLogin\Tests\Fixtures\Panels\AppPanelProvider;
use Arzcode\FilamentMagicLogin\Tests\Fixtures\Panels\CustomLoginPanelProvider;
use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Closure;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * Storage driver under test. The whole feature suite runs against both.
     */
    public static string $storageDriver = 'database';

    /**
     * Extra config applied while the application boots.
     *
     * @var array<string, mixed>
     */
    public static array $config = [];

    public static bool $registerCustomLoginPanel = false;

    protected function setUp(): void
    {
        parent::setUp();

        User::unguard();
    }

    protected function tearDown(): void
    {
        static::$config = [];
        static::$registerCustomLoginPanel = false;
        AdminPanelProvider::$configurePlugin = null;
        AdminPanelProvider::$configurePanel = null;
        AppPanelProvider::$configurePlugin = null;
        CustomLoginPanelProvider::$configurePlugin = null;
        CustomLoginPanelProvider::$pluginBeforeLogin = false;
        CustomLoginPanelProvider::$loginPage = \Filament\Auth\Pages\Login::class;

        parent::tearDown();
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return array_values(array_filter([
            ActionsServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeIconsServiceProvider::class,
            FilamentServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            LivewireServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            SupportServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            FilamentMagicLoginServiceProvider::class,
            AdminPanelProvider::class,
            AppPanelProvider::class,
            static::$registerCustomLoginPanel ? CustomLoginPanelProvider::class : null,
        ]));
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('mail.default', 'array');
        $app['config']->set('view.paths', [__DIR__ . '/Fixtures/views', resource_path('views')]);

        $app['config']->set('filament-magic-login.storage.driver', static::$storageDriver);

        foreach (static::$config as $key => $value) {
            $app['config']->set($key, $value);
        }
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->runPackageMigrations();
    }

    protected function runPackageMigrations(): void
    {
        (include __DIR__ . '/Fixtures/database/migrations/0001_01_01_000000_create_users_table.php')->up();
        (include __DIR__ . '/../database/migrations/create_magic_login_tokens_table.php.stub')->up();
    }

    /**
     * Rebuilds the application so panel-level configuration can change per test.
     *
     * @param  array<string, mixed>  $config
     */
    protected function rebootWith(array $config = [], ?Closure $configurePlugin = null, ?Closure $configurePanel = null): void
    {
        static::$config = [...static::$config, ...$config];

        if ($configurePlugin !== null) {
            AdminPanelProvider::$configurePlugin = $configurePlugin;
        }

        if ($configurePanel !== null) {
            AdminPanelProvider::$configurePanel = $configurePanel;
        }

        $this->refreshApplication();
        $this->runPackageMigrations();
    }

    protected function getApplicationTimezone($app): string
    {
        return 'UTC';
    }
}
