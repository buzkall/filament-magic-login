<?php

use Arzcode\FilamentMagicLogin\Models\MagicLoginToken;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

function lang(string $key, array $replace = []): string
{
    return __("filament-magic-login::filament-magic-login.uninstall.{$key}", $replace);
}

function publishPackageFiles(): array
{
    $config = config_path('filament-magic-login.php');
    $migration = database_path('migrations/2026_01_01_000000_create_magic_login_tokens_table.php');

    File::ensureDirectoryExists(dirname($config));
    File::ensureDirectoryExists(dirname($migration));
    File::put($config, '<?php return [];');
    File::put($migration, '<?php return new class {};');

    return [$config, $migration];
}

afterEach(function (): void {
    File::delete(config_path('filament-magic-login.php'));

    foreach (File::glob(database_path('migrations/*_create_magic_login_tokens_table.php')) as $file) {
        File::delete($file);
    }
});

it('removes the published files and drops the table when confirmed', function (): void {
    [$config, $migration] = publishPackageFiles();

    expect(Schema::hasTable('magic_login_tokens'))->toBeTrue();

    $this->artisan('filament-magic-login:uninstall')
        ->expectsConfirmation(lang('confirm'), 'yes')
        ->expectsConfirmation(lang('drop_table', ['table' => 'magic_login_tokens']), 'yes')
        ->expectsOutputToContain(lang('done'))
        ->assertSuccessful();

    expect(Schema::hasTable('magic_login_tokens'))->toBeFalse()
        ->and(File::exists($config))->toBeFalse()
        ->and(File::exists($migration))->toBeFalse();
});

it('changes nothing when the confirmation is declined', function (): void {
    [$config, $migration] = publishPackageFiles();

    $this->artisan('filament-magic-login:uninstall')
        ->expectsConfirmation(lang('confirm'), 'no')
        ->expectsOutputToContain(lang('aborted'))
        ->assertSuccessful();

    expect(Schema::hasTable('magic_login_tokens'))->toBeTrue()
        ->and(File::exists($config))->toBeTrue()
        ->and(File::exists($migration))->toBeTrue();
});

it('keeps the table when the drop is declined', function (): void {
    publishPackageFiles();

    $this->artisan('filament-magic-login:uninstall')
        ->expectsConfirmation(lang('confirm'), 'yes')
        ->expectsConfirmation(lang('drop_table', ['table' => 'magic_login_tokens']), 'no')
        ->expectsOutputToContain(lang('table_kept', ['table' => 'magic_login_tokens']))
        ->assertSuccessful();

    expect(Schema::hasTable('magic_login_tokens'))->toBeTrue()
        ->and(File::exists(config_path('filament-magic-login.php')))->toBeFalse();
});

it('keeps the table with --keep-tokens and asks nothing with --force', function (): void {
    MagicLoginToken::query()->create([
        'authenticatable_type' => 'user',
        'authenticatable_id' => 1,
        'token_hash' => str_repeat('a', 64),
        'panel_id' => 'admin',
        'guard' => 'web',
        'expires_at' => now()->addMinutes(15),
    ]);

    $this->artisan('filament-magic-login:uninstall', ['--force' => true, '--keep-tokens' => true])
        ->assertSuccessful();

    expect(Schema::hasTable('magic_login_tokens'))->toBeTrue()
        ->and(MagicLoginToken::query()->count())->toBe(1);
});

it('drops the table without prompting with --force', function (): void {
    $this->artisan('filament-magic-login:uninstall', ['--force' => true])
        ->expectsOutputToContain(lang('table_dropped', ['table' => 'magic_login_tokens']))
        ->assertSuccessful();

    expect(Schema::hasTable('magic_login_tokens'))->toBeFalse();
});

it('names the panels the plugin is still registered on with --keep-code', function (): void {
    $this->artisan('filament-magic-login:uninstall', ['--force' => true, '--keep-code' => true])
        ->expectsOutputToContain('admin')
        ->assertSuccessful();
});

it('strips the plugin registration out of the application code', function (): void {
    $provider = app_path('Providers/Filament/AdminPanelProvider.php');

    File::ensureDirectoryExists(dirname($provider));
    File::put($provider, <<<'PHP'
    <?php

    namespace App\Providers\Filament;

    use Arzcode\FilamentMagicLogin\MagicLoginPlugin;
    use Filament\Panel;
    use Filament\PanelProvider;

    class AdminPanelProvider extends PanelProvider
    {
        public function panel(Panel $panel): Panel
        {
            return $panel
                ->id('admin')
                ->login()
                ->plugin(MagicLoginPlugin::make()->expiresAfter(10))
                ->authGuard('web');
        }
    }
    PHP);

    $this->artisan('filament-magic-login:uninstall', ['--force' => true])
        ->expectsOutputToContain(__('filament-magic-login::filament-magic-login.uninstall.code_updated', [
            'path' => 'app/Providers/Filament/AdminPanelProvider.php',
        ]))
        ->assertSuccessful();

    $rewritten = File::get($provider);

    expect($rewritten)->not->toContain('MagicLoginPlugin')
        ->and($rewritten)->toContain("->login()\n            ->authGuard('web')")
        ->and(php_syntax_ok($rewritten))->toBeTrue();

    File::delete($provider);
});

it('leaves the application code alone with --keep-code', function (): void {
    $provider = app_path('Providers/Filament/AdminPanelProvider.php');

    File::ensureDirectoryExists(dirname($provider));
    File::put($provider, "<?php\n\nuse Arzcode\\FilamentMagicLogin\\MagicLoginPlugin;\n\nclass AdminPanelProvider { public function panel(\$panel) { return \$panel->plugin(MagicLoginPlugin::make()); } }\n");

    $this->artisan('filament-magic-login:uninstall', ['--force' => true, '--keep-code' => true])
        ->assertSuccessful();

    expect(File::get($provider))->toContain('MagicLoginPlugin');

    File::delete($provider);
});

it('reports a missing table instead of failing', function (): void {
    Schema::drop('magic_login_tokens');

    $this->artisan('filament-magic-login:uninstall', ['--force' => true])
        ->expectsOutputToContain(lang('table_missing', ['table' => 'magic_login_tokens']))
        ->assertSuccessful();
});
