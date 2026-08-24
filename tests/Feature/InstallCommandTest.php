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

beforeEach(function (): void {
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

    File::delete(config_path('filament-magic-login.php'));

    foreach (File::glob(database_path('migrations/*_create_magic_login_tokens_table.php')) as $file) {
        File::delete($file);
    }
});

it('publishes, migrates and schedules the pruner for the database driver', function (): void {
    config()->set('filament-magic-login.storage.driver', 'database');
    writeConsoleRoutes("<?php\n\nuse Illuminate\\Foundation\\Inspiring;\n");

    $this->artisan('filament-magic-login:install')
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
        ->expectsConfirmation(installLang('run_migrations'), 'no')
        ->expectsConfirmation(installLang('schedule_prompt'), 'no')
        ->expectsOutputToContain(installLang('schedule_manual'))
        ->assertSuccessful();

    expect(File::get(consoleRoutes()))->toBe($original);
});
