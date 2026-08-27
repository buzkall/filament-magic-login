<?php

use Arzcode\FilamentMagicLogin\Tests\Fixtures\Models\User;
use Illuminate\Support\Facades\File;

function installLang(string $key, array $replace = []): string
{
    return __("filament-magic-login::filament-magic-login.install.{$key}", $replace);
}

function consoleRoutes(): string
{
    return base_path('routes/console.php');
}

function writeConsoleRoutes(string $code): void
{
    File::ensureDirectoryExists(dirname(consoleRoutes()));
    File::put(consoleRoutes(), $code);
}

function panelProvider(): string
{
    return app_path('Providers/Filament/AdminPanelProvider.php');
}

function writePanelProvider(string $chain): void
{
    File::ensureDirectoryExists(dirname(panelProvider()));

    File::put(panelProvider(), <<<PHP
    <?php

    namespace App\Providers\Filament;

    use Filament\Panel;
    use Filament\PanelProvider;

    class AdminPanelProvider extends PanelProvider
    {
        public function panel(Panel \$panel): Panel
        {
    {$chain}
        }
    }

    PHP);
}

function stockPanelChain(): string
{
    return <<<'PHP'
            return $panel
                ->default()
                ->id('admin')
                ->path('admin')
                ->login();
    PHP;
}

/**
 * Everything the install command can write into the skeleton application.
 *
 * Cleaned both before and after each test: an expectation that fails leaves its
 * PendingCommand to run from the destructor, which can publish files after the test
 * that made them has already finished.
 */
function userResourcePath(): string
{
    return app_path('Filament/Resources/Users/UserResource.php');
}

function usersTablePath(): string
{
    return app_path('Filament/Resources/Users/Tables/UsersTable.php');
}

function viewUserPath(): string
{
    return app_path('Filament/Resources/Users/Pages/ViewUser.php');
}

/**
 * Writes the shape `make:filament-resource` generates: a resource that hands its table
 * off to an extracted class, and a record page with its own header actions.
 */
function writeUserResource(): void
{
    $model = User::class;

    File::ensureDirectoryExists(dirname(userResourcePath()));
    File::put(userResourcePath(), <<<PHP
    <?php

    namespace App\Filament\Resources\Users;

    use App\Filament\Resources\Users\Tables\UsersTable;
    use Filament\Resources\Resource;
    use Filament\Tables\Table;
    use {$model};

    class UserResource extends Resource
    {
        protected static ?string \$model = User::class;

        public static function table(Table \$table): Table
        {
            return UsersTable::configure(\$table);
        }
    }

    PHP);

    File::ensureDirectoryExists(dirname(usersTablePath()));
    File::put(usersTablePath(), <<<'PHP'
    <?php

    namespace App\Filament\Resources\Users\Tables;

    use Filament\Actions\EditAction;
    use Filament\Tables\Table;

    class UsersTable
    {
        public static function configure(Table $table): Table
        {
            return $table
                ->recordActions([
                    EditAction::make(),
                ]);
        }
    }

    PHP);

    File::ensureDirectoryExists(dirname(viewUserPath()));
    File::put(viewUserPath(), <<<'PHP'
    <?php

    namespace App\Filament\Resources\Users\Pages;

    use App\Filament\Resources\Users\UserResource;
    use Filament\Actions\EditAction;
    use Filament\Resources\Pages\ViewRecord;

    class ViewUser extends ViewRecord
    {
        protected static string $resource = UserResource::class;

        protected function getHeaderActions(): array
        {
            return [
                EditAction::make(),
            ];
        }
    }

    PHP);
}

function cleanInstalledFiles(): void
{
    File::delete(config_path('filament-magic-login.php'));

    foreach (File::glob(database_path('migrations/*_create_magic_login_tokens_table.php')) as $file) {
        File::delete($file);
    }

    // Only ever removes what these tests wrote: the skeleton application ships no
    // PHP files of its own under app/.
    File::deleteDirectory(app_path('Providers'));
    File::deleteDirectory(app_path('Filament'));
}

beforeEach(function (): void {
    cleanInstalledFiles();

    $this->previousConsoleRoutes = File::exists(consoleRoutes())
        ? File::get(consoleRoutes())
        : null;
});

afterEach(function (): void {
    if ($this->previousConsoleRoutes === null) {
        File::delete(consoleRoutes());
    } else {
        File::put(consoleRoutes(), $this->previousConsoleRoutes);
    }

    cleanInstalledFiles();
});

it('publishes, migrates and schedules the pruner for the database driver', function (): void {
    config()->set('filament-magic-login.storage.driver', 'database');
    writeConsoleRoutes("<?php\n\nuse Illuminate\\Foundation\\Inspiring;\n");

    $this->artisan('filament-magic-login:install')
        ->expectsConfirmation(installLang('config_publish'), 'yes')
        ->expectsConfirmation(installLang('run_migrations'), 'no')
        ->expectsConfirmation(installLang('schedule_prompt'), 'yes')
        ->assertSuccessful();

    expect(File::get(consoleRoutes()))
        ->toContain('use Illuminate\Support\Facades\Schedule;')
        ->toContain('use Arzcode\FilamentMagicLogin\Models\MagicLoginToken;')
        ->toContain("Schedule::command('model:prune'")
        ->and(File::exists(config_path('filament-magic-login.php')))->toBeTrue()
        ->and(File::glob(database_path('migrations/*_create_magic_login_tokens_table.php')))->not->toBe([]);
});

it('leaves the console routes alone when the pruner is declined', function (): void {
    config()->set('filament-magic-login.storage.driver', 'database');
    $original = "<?php\n\nuse Illuminate\\Foundation\\Inspiring;\n";
    writeConsoleRoutes($original);

    $this->artisan('filament-magic-login:install')
        ->expectsConfirmation(installLang('config_publish'), 'yes')
        ->expectsConfirmation(installLang('run_migrations'), 'no')
        ->expectsConfirmation(installLang('schedule_prompt'), 'no')
        ->expectsOutputToContain(installLang('schedule_manual'))
        ->assertSuccessful();

    expect(File::get(consoleRoutes()))->toBe($original);
});

it('does not ask again when the pruner is already scheduled', function (): void {
    config()->set('filament-magic-login.storage.driver', 'database');
    $original = "<?php\n\nSchedule::command('model:prune', ['--model' => [MagicLoginToken::class]])->daily();\n";
    writeConsoleRoutes($original);

    $this->artisan('filament-magic-login:install')
        ->expectsConfirmation(installLang('config_publish'), 'yes')
        ->expectsConfirmation(installLang('run_migrations'), 'no')
        ->expectsOutputToContain(installLang('schedule_exists'))
        ->assertSuccessful();

    expect(File::get(consoleRoutes()))->toBe($original);
});

it('keeps a config file that is already published unless told otherwise', function (): void {
    config()->set('filament-magic-login.storage.driver', 'database');
    writeConsoleRoutes("<?php\n");
    File::put(config_path('filament-magic-login.php'), '<?php return ["mine" => true];');

    $this->artisan('filament-magic-login:install')
        ->expectsConfirmation(installLang('config_overwrite'), 'no')
        ->expectsConfirmation(installLang('run_migrations'), 'no')
        ->expectsConfirmation(installLang('schedule_prompt'), 'no')
        ->assertSuccessful();

    expect(File::get(config_path('filament-magic-login.php')))->toBe('<?php return ["mine" => true];');
});

it('skips the migration and pruning steps for the cache driver', function (): void {
    config()->set('filament-magic-login.storage.driver', 'cache');
    $original = "<?php\n";
    writeConsoleRoutes($original);

    $this->artisan('filament-magic-login:install')
        ->expectsConfirmation(installLang('config_publish'), 'yes')
        ->expectsOutputToContain(installLang('cache_driver_skip_migrations'))
        ->assertSuccessful();

    expect(File::get(consoleRoutes()))->toBe($original)
        ->and(File::glob(database_path('migrations/*_create_magic_login_tokens_table.php')))->toBe([]);
});

it('offers to register the plugin in a panel provider', function (): void {
    config()->set('filament-magic-login.storage.driver', 'database');
    writeConsoleRoutes("<?php\n");
    writePanelProvider(stockPanelChain());

    $this->artisan('filament-magic-login:install')
        ->expectsConfirmation(installLang('config_publish'), 'yes')
        ->expectsConfirmation(installLang('run_migrations'), 'no')
        ->expectsConfirmation(installLang('schedule_prompt'), 'no')
        ->expectsConfirmation(installLang('plugin_prompt', ['path' => 'app/Providers/Filament/AdminPanelProvider.php']), 'yes')
        ->assertSuccessful();

    expect(File::get(panelProvider()))
        ->toContain('use Arzcode\FilamentMagicLogin\MagicLoginPlugin;')
        ->toContain("            ->login()\n            ->plugin(MagicLoginPlugin::make());");
});

it('leaves the panel provider alone when the offer is declined', function (): void {
    config()->set('filament-magic-login.storage.driver', 'database');
    writeConsoleRoutes("<?php\n");
    writePanelProvider(stockPanelChain());
    $original = File::get(panelProvider());

    $this->artisan('filament-magic-login:install')
        ->expectsConfirmation(installLang('config_publish'), 'yes')
        ->expectsConfirmation(installLang('run_migrations'), 'no')
        ->expectsConfirmation(installLang('schedule_prompt'), 'no')
        ->expectsConfirmation(installLang('plugin_prompt', ['path' => 'app/Providers/Filament/AdminPanelProvider.php']), 'no')
        ->expectsOutputToContain(installLang('plugin_manual'))
        ->assertSuccessful();

    expect(File::get(panelProvider()))->toBe($original);
});

it('does not ask again when the plugin is already registered', function (): void {
    config()->set('filament-magic-login.storage.driver', 'database');
    writeConsoleRoutes("<?php\n");
    writePanelProvider(<<<'PHP'
            return $panel
                ->id('admin')
                ->plugin(\Arzcode\FilamentMagicLogin\MagicLoginPlugin::make());
    PHP);
    $original = File::get(panelProvider());

    // An unexpected confirmation would fail the test, which is the assertion that
    // re-running the installer stays quiet.
    $this->artisan('filament-magic-login:install')
        ->expectsConfirmation(installLang('config_publish'), 'yes')
        ->expectsConfirmation(installLang('run_migrations'), 'no')
        ->expectsConfirmation(installLang('schedule_prompt'), 'no')
        ->expectsOutputToContain(installLang('plugin_registered', ['path' => 'app/Providers/Filament/AdminPanelProvider.php']))
        ->assertSuccessful();

    expect(File::get(panelProvider()))->toBe($original);
});

it('explains the registration by hand when there is no panel provider', function (): void {
    config()->set('filament-magic-login.storage.driver', 'database');
    writeConsoleRoutes("<?php\n");

    $this->artisan('filament-magic-login:install')
        ->expectsConfirmation(installLang('config_publish'), 'yes')
        ->expectsConfirmation(installLang('run_migrations'), 'no')
        ->expectsConfirmation(installLang('schedule_prompt'), 'no')
        ->expectsOutputToContain(installLang('plugin_manual'))
        ->expectsOutputToContain('->plugin(MagicLoginPlugin::make())')
        ->assertSuccessful();
});

it('reports a panel provider it cannot edit with certainty', function (): void {
    config()->set('filament-magic-login.storage.driver', 'database');
    writeConsoleRoutes("<?php\n");
    // Two exits, so there is no single chain to append to.
    writePanelProvider(<<<'PHP'
            if ($panel->getId() === 'admin') {
                return $panel->login();
            }

            return $panel;
    PHP);
    $original = File::get(panelProvider());

    $this->artisan('filament-magic-login:install')
        ->expectsConfirmation(installLang('config_publish'), 'yes')
        ->expectsConfirmation(installLang('run_migrations'), 'no')
        ->expectsConfirmation(installLang('schedule_prompt'), 'no')
        ->expectsConfirmation(installLang('plugin_prompt', ['path' => 'app/Providers/Filament/AdminPanelProvider.php']), 'yes')
        ->expectsOutputToContain(installLang('plugin_failed', ['path' => 'app/Providers/Filament/AdminPanelProvider.php']))
        ->expectsOutputToContain(installLang('plugin_manual'))
        ->assertSuccessful();

    expect(File::get(panelProvider()))->toBe($original);
});

it('leaves a panel provider alone when the registration is declined', function (): void {
    config()->set('filament-magic-login.storage.driver', 'database');
    writeConsoleRoutes("<?php\n");
    writePanelProvider(stockPanelChain());
    $original = File::get(panelProvider());

    $this->artisan('filament-magic-login:install', ['--no-interaction' => true])
        ->expectsConfirmation(installLang('config_publish'), 'yes')
        ->expectsConfirmation(installLang('run_migrations'), 'no')
        ->expectsConfirmation(installLang('schedule_prompt'), 'no')
        ->expectsConfirmation(installLang('plugin_prompt', ['path' => 'app/Providers/Filament/AdminPanelProvider.php']), 'no')
        ->assertSuccessful();

    expect(File::get(panelProvider()))->toBe($original);
});

it('still offers the registration on the cache driver', function (): void {
    config()->set('filament-magic-login.storage.driver', 'cache');
    writeConsoleRoutes("<?php\n");
    writePanelProvider(stockPanelChain());

    // The cache driver skips the table and the pruner, but a panel still needs the
    // plugin for any of this to show up on a login page.
    $this->artisan('filament-magic-login:install')
        ->expectsConfirmation(installLang('config_publish'), 'yes')
        ->expectsOutputToContain(installLang('cache_driver_skip_migrations'))
        ->expectsConfirmation(installLang('plugin_prompt', ['path' => 'app/Providers/Filament/AdminPanelProvider.php']), 'yes')
        ->assertSuccessful();

    expect(File::get(panelProvider()))->toContain('->plugin(MagicLoginPlugin::make())');
});

it('skips the config file when it is declined', function (): void {
    config()->set('filament-magic-login.storage.driver', 'database');
    writeConsoleRoutes("<?php\n");

    // The provider merges the package defaults, so an application that never publishes
    // the file is a supported install rather than a broken one.
    $this->artisan('filament-magic-login:install')
        ->expectsConfirmation(installLang('config_publish'), 'no')
        ->expectsOutputToContain(installLang('config_skipped'))
        ->expectsConfirmation(installLang('run_migrations'), 'no')
        ->expectsConfirmation(installLang('schedule_prompt'), 'no')
        ->assertSuccessful();

    expect(File::exists(config_path('filament-magic-login.php')))->toBeFalse();
});

it('offers to add the action to the user resource table and its record pages', function (): void {
    config()->set('filament-magic-login.storage.driver', 'database');
    writeConsoleRoutes("<?php\n");
    writeUserResource();

    $this->artisan('filament-magic-login:install')
        ->expectsConfirmation(installLang('config_publish'), 'yes')
        ->expectsConfirmation(installLang('run_migrations'), 'no')
        ->expectsConfirmation(installLang('schedule_prompt'), 'no')
        ->expectsConfirmation(installLang('resource_prompt', ['path' => 'app/Filament/Resources/Users/Tables/UsersTable.php']), 'yes')
        ->expectsConfirmation(installLang('resource_prompt', ['path' => 'app/Filament/Resources/Users/Pages/ViewUser.php']), 'yes')
        ->assertSuccessful();

    expect(File::get(usersTablePath()))
        ->toContain('use Arzcode\FilamentMagicLogin\Actions\SendMagicLinkAction;')
        ->toContain("                EditAction::make(),\n                SendMagicLinkAction::make(),");

    expect(File::get(viewUserPath()))
        ->toContain('use Arzcode\FilamentMagicLogin\Actions\SendMagicLinkAction;')
        ->toContain("            EditAction::make(),\n            SendMagicLinkAction::make(),");

    // The resource itself owns neither array, so it is left alone.
    expect(File::get(userResourcePath()))->not->toContain('SendMagicLinkAction');

    expect(php_syntax_ok(File::get(usersTablePath())))->toBeTrue()
        ->and(php_syntax_ok(File::get(viewUserPath())))->toBeTrue();
});

it('leaves the resource untouched when the offer is declined', function (): void {
    config()->set('filament-magic-login.storage.driver', 'database');
    writeConsoleRoutes("<?php\n");
    writeUserResource();

    $table = File::get(usersTablePath());
    $page = File::get(viewUserPath());

    $this->artisan('filament-magic-login:install')
        ->expectsConfirmation(installLang('config_publish'), 'yes')
        ->expectsConfirmation(installLang('run_migrations'), 'no')
        ->expectsConfirmation(installLang('schedule_prompt'), 'no')
        ->expectsConfirmation(installLang('resource_prompt', ['path' => 'app/Filament/Resources/Users/Tables/UsersTable.php']), 'no')
        ->expectsConfirmation(installLang('resource_prompt', ['path' => 'app/Filament/Resources/Users/Pages/ViewUser.php']), 'no')
        ->expectsOutputToContain(installLang('resource_manual'))
        ->expectsOutputToContain('SendMagicLinkAction::make()')
        ->assertSuccessful();

    expect(File::get(usersTablePath()))->toBe($table)
        ->and(File::get(viewUserPath()))->toBe($page);
});

it('never edits a resource without a terminal to ask', function (): void {
    config()->set('filament-magic-login.storage.driver', 'database');
    writeConsoleRoutes("<?php\n");
    writeUserResource();

    $table = File::get(usersTablePath());
    $page = File::get(viewUserPath());

    // Answered no: a resource the installer offers to wire is left exactly as it was.
    $this->artisan('filament-magic-login:install', ['--no-interaction' => true])
        ->expectsConfirmation(installLang('config_publish'), 'yes')
        ->expectsConfirmation(installLang('run_migrations'), 'no')
        ->expectsConfirmation(installLang('schedule_prompt'), 'no')
        ->expectsConfirmation(installLang('resource_prompt', ['path' => 'app/Filament/Resources/Users/Tables/UsersTable.php']), 'no')
        ->expectsConfirmation(installLang('resource_prompt', ['path' => 'app/Filament/Resources/Users/Pages/ViewUser.php']), 'no')
        ->assertSuccessful();

    expect(File::get(usersTablePath()))->toBe($table)
        ->and(File::get(viewUserPath()))->toBe($page);
});

it('stays quiet about a resource that already has the action', function (): void {
    config()->set('filament-magic-login.storage.driver', 'database');
    writeConsoleRoutes("<?php\n");
    writeUserResource();

    foreach ([usersTablePath(), viewUserPath()] as $path) {
        File::put($path, str_replace(
            'EditAction::make(),',
            "EditAction::make(),\n                \Arzcode\FilamentMagicLogin\Actions\SendMagicLinkAction::make(),",
            File::get($path),
        ));
    }

    // An unexpected confirmation would fail the test, which is the assertion that
    // re-running the installer stays quiet.
    $this->artisan('filament-magic-login:install')
        ->expectsConfirmation(installLang('config_publish'), 'yes')
        ->expectsConfirmation(installLang('run_migrations'), 'no')
        ->expectsConfirmation(installLang('schedule_prompt'), 'no')
        ->expectsOutputToContain(installLang('resource_exists', ['path' => 'app/Filament/Resources/Users/Tables/UsersTable.php']))
        ->assertSuccessful();
});

it('reports rather than guesses when two resources claim the same model', function (): void {
    config()->set('filament-magic-login.storage.driver', 'database');
    writeConsoleRoutes("<?php\n");
    writeUserResource();

    File::put(app_path('Filament/Resources/Users/StaffResource.php'), str_replace(
        'class UserResource',
        'class StaffResource',
        File::get(userResourcePath()),
    ));

    $this->artisan('filament-magic-login:install')
        ->expectsConfirmation(installLang('config_publish'), 'yes')
        ->expectsConfirmation(installLang('run_migrations'), 'no')
        ->expectsConfirmation(installLang('schedule_prompt'), 'no')
        ->expectsOutputToContain(installLang('resource_manual'))
        ->assertSuccessful();

    expect(File::get(usersTablePath()))->not->toContain('SendMagicLinkAction');
});
