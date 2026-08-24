<?php

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
function cleanInstalledFiles(): void
{
    File::delete(config_path('filament-magic-login.php'));

    foreach (File::glob(database_path('migrations/*_create_magic_login_tokens_table.php')) as $file) {
        File::delete($file);
    }

    // Only ever removes what these tests wrote: the skeleton application ships no
    // PHP files of its own under app/.
    File::deleteDirectory(app_path('Providers'));
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

it('never edits the console routes on an unattended install', function (): void {
    config()->set('filament-magic-login.storage.driver', 'database');
    $original = "<?php\n";
    writeConsoleRoutes($original);

    // With no terminal to ask, Prompts answers with each default — and the pruner
    // question defaults to no precisely so this cannot rewrite somebody's source file.
    $this->artisan('filament-magic-login:install', ['--no-interaction' => true])
        ->expectsConfirmation(installLang('config_publish'), 'yes')
        ->expectsConfirmation(installLang('run_migrations'), 'no')
        ->expectsConfirmation(installLang('schedule_prompt'), 'no')
        ->expectsOutputToContain(installLang('schedule_manual'))
        ->assertSuccessful();

    expect(File::get(consoleRoutes()))->toBe($original);
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

it('never edits a panel provider on an unattended install', function (): void {
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
