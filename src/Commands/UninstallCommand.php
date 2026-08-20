<?php

namespace Arzcode\FilamentMagicLogin\Commands;

use Arzcode\FilamentMagicLogin\MagicLoginPlugin;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;

/**
 * Undoes what the install command published, and clears the stored tokens.
 *
 * Everything destructive is confirmed first, and code the developer wrote by hand
 * (panel registration, the trait on a custom login page) is only reported, never
 * rewritten.
 */
class UninstallCommand extends Command
{
    protected $signature = 'filament-magic-login:uninstall
        {--force : Skip every confirmation}
        {--keep-tokens : Leave the magic_login_tokens table in place}';

    public function __construct(private readonly Filesystem $filesystem)
    {
        parent::__construct();

        $this->setDescription(__('filament-magic-login::filament-magic-login.uninstall.description'));
    }

    public function handle(): int
    {
        if (! $this->confirmToProceed()) {
            $this->comment(__('filament-magic-login::filament-magic-login.uninstall.aborted'));

            return static::SUCCESS;
        }

        $this->reportRegisteredPanels();
        $this->dropTokensTable();
        $this->deletePublishedFiles();

        $this->newLine();
        $this->info(__('filament-magic-login::filament-magic-login.uninstall.next_steps'));
        $this->info(__('filament-magic-login::filament-magic-login.uninstall.done'));

        return static::SUCCESS;
    }

    protected function confirmToProceed(): bool
    {
        if ($this->option('force')) {
            return true;
        }

        return $this->confirm(__('filament-magic-login::filament-magic-login.uninstall.confirm'), false);
    }

    /**
     * Naming the panels is the one thing this command can do about code it must not
     * edit: the developer still has to remove the plugin call by hand.
     */
    protected function reportRegisteredPanels(): void
    {
        $panels = array_keys(array_filter(
            Filament::getPanels(),
            fn (Panel $panel): bool => $panel->hasPlugin(MagicLoginPlugin::ID),
        ));

        if ($panels === []) {
            return;
        }

        $this->warn(__('filament-magic-login::filament-magic-login.uninstall.panels_warning', [
            'panels' => implode(', ', $panels),
        ]));
    }

    protected function dropTokensTable(): void
    {
        $table = (string) config('filament-magic-login.storage.table', 'magic_login_tokens');

        if (config('filament-magic-login.storage.driver') === 'cache') {
            $this->comment(__('filament-magic-login::filament-magic-login.uninstall.cache_note'));
        }

        if ($this->option('keep-tokens')) {
            $this->comment(__('filament-magic-login::filament-magic-login.uninstall.table_kept', ['table' => $table]));

            return;
        }

        if (! Schema::hasTable($table)) {
            $this->comment(__('filament-magic-login::filament-magic-login.uninstall.table_missing', ['table' => $table]));

            return;
        }

        if (
            (! $this->option('force')) &&
            (! $this->confirm(__('filament-magic-login::filament-magic-login.uninstall.drop_table', ['table' => $table]), false))
        ) {
            $this->comment(__('filament-magic-login::filament-magic-login.uninstall.table_kept', ['table' => $table]));

            return;
        }

        Schema::drop($table);

        $this->info(__('filament-magic-login::filament-magic-login.uninstall.table_dropped', ['table' => $table]));
    }

    protected function deletePublishedFiles(): void
    {
        $this->deletePath(config_path('filament-magic-login.php'));

        foreach ($this->publishedMigrations() as $migration) {
            $this->deletePath($migration);
        }

        $translations = function_exists('lang_path')
            ? lang_path('vendor/filament-magic-login')
            : resource_path('lang/vendor/filament-magic-login');

        if ($this->filesystem->isDirectory($translations)) {
            $this->filesystem->deleteDirectory($translations);

            $this->info(__('filament-magic-login::filament-magic-login.uninstall.deleted', ['path' => $translations]));
        }
    }

    /**
     * @return array<int, string>
     */
    protected function publishedMigrations(): array
    {
        return $this->filesystem->glob(
            database_path('migrations/*_create_magic_login_tokens_table.php'),
        );
    }

    protected function deletePath(string $path): void
    {
        if (! $this->filesystem->exists($path)) {
            $this->comment(__('filament-magic-login::filament-magic-login.uninstall.missing', ['path' => $path]));

            return;
        }

        $this->filesystem->delete($path);

        $this->info(__('filament-magic-login::filament-magic-login.uninstall.deleted', ['path' => $path]));
    }
}
