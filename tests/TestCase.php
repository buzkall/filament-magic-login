<?php

namespace Arzcode\FilamentMagicLogin\Tests;

use Arzcode\FilamentMagicLogin\FilamentMagicLoginServiceProvider;
use Arzcode\FilamentMagicLogin\Tests\Fixtures\Filament\Resources\Users\Pages\EditUser;
use Arzcode\FilamentMagicLogin\Tests\Fixtures\Filament\Resources\Users\Pages\ViewUser;
use Arzcode\FilamentMagicLogin\Tests\Fixtures\Filament\Resources\Users\Tables\UsersTable;
use Arzcode\FilamentMagicLogin\Tests\Fixtures\Models\User;
use Arzcode\FilamentMagicLogin\Tests\Fixtures\MultiFactor\AlwaysOnMultiFactorProvider;
use Arzcode\FilamentMagicLogin\Tests\Fixtures\Panels\AdminPanelProvider;
use Arzcode\FilamentMagicLogin\Tests\Fixtures\Panels\AppPanelProvider;
use Arzcode\FilamentMagicLogin\Tests\Fixtures\Panels\CustomLoginPanelProvider;
use Arzcode\FilamentMagicLogin\Tests\Fixtures\Panels\PluginlessPanelProvider;
use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Closure;
use Filament\Actions\ActionsServiceProvider;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Support\Facades\Notification;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * Storage driver under test. The whole feature suite runs against both.
     */
    public static string $storageDriver = '';

    /**
     * Extra config applied while the application boots.
     *
     * @var array<string, mixed>
     */
    public static array $config = [];

    public static bool $registerCustomLoginPanel = false;

    public static bool $registerPluginlessPanel = false;

    /**
     * Set through the MAGIC_LOGIN_STORAGE env var so `composer test` can run the
     * whole suite once per driver.
     */
    public static function storageDriver(): string
    {
        return static::$storageDriver ?: (string) (env('MAGIC_LOGIN_STORAGE') ?: 'database');
    }

    protected function setUp(): void
    {
        parent::setUp();

        User::unguard();
    }

    protected function tearDown(): void
    {
        static::$config = [];
        static::$storageDriver = '';
        static::$registerCustomLoginPanel = false;
        static::$registerPluginlessPanel = false;
        AlwaysOnMultiFactorProvider::$enabled = true;
        AdminPanelProvider::$configurePlugin = null;
        AdminPanelProvider::$configurePanel = null;
        UsersTable::$configureAction = null;
        ViewUser::$configureAction = null;
        EditUser::$configureAction = null;
        AppPanelProvider::$configurePlugin = null;
        CustomLoginPanelProvider::$configurePlugin = null;
        CustomLoginPanelProvider::$pluginBeforeLogin = false;
        CustomLoginPanelProvider::$loginPage = Login::class;

        parent::tearDown();
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return array_values(array_filter([
            BladeCaptureDirectiveServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeIconsServiceProvider::class,
            SupportServiceProvider::class,
            ActionsServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            // Livewire must register after Filament's support provider, which rebinds
            // the Livewire DataStore; otherwise the store is never shared.
            LivewireServiceProvider::class,
            FilamentServiceProvider::class,
            FilamentMagicLoginServiceProvider::class,
            AdminPanelProvider::class,
            AppPanelProvider::class,
            static::$registerCustomLoginPanel ? CustomLoginPanelProvider::class : null,
            static::$registerPluginlessPanel ? PluginlessPanelProvider::class : null,
        ]));
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('mail.default', 'array');
        $app['config']->set('view.paths', [__DIR__.'/Fixtures/views', resource_path('views')]);

        $app['config']->set('filament-magic-login.storage.driver', static::storageDriver());

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
        (include __DIR__.'/Fixtures/database/migrations/0001_01_01_000000_create_users_table.php')->up();
        (include __DIR__.'/../database/migrations/create_magic_login_tokens_table.php.stub')->up();
    }

    /**
     * Rebuilds the application so panel-level configuration can change per test.
     *
     * @param  array<string, mixed>  $config
     */
    protected function rebootWith(array $config = [], ?Closure $configurePlugin = null, ?Closure $configurePanel = null, ?Closure $configureAppPlugin = null): void
    {
        static::$config = [...static::$config, ...$config];

        if ($configurePlugin !== null) {
            AdminPanelProvider::$configurePlugin = $configurePlugin;
        }

        if ($configurePanel !== null) {
            AdminPanelProvider::$configurePanel = $configurePanel;
        }

        if ($configureAppPlugin !== null) {
            AppPanelProvider::$configurePlugin = $configureAppPlugin;
        }

        $this->refreshApplication();
        $this->runPackageMigrations();

        // Refreshing the application discards the per-test state set up in setUp().
        User::unguard();
        Notification::fake();
        Filament::setCurrentPanel('admin');
    }

    protected function getApplicationTimezone($app): string
    {
        return 'UTC';
    }
}
