<?php

namespace Arzcode\FilamentMagicLogin\Commands;

use Arzcode\FilamentMagicLogin\MagicLoginPlugin;
use Arzcode\FilamentMagicLogin\Support\PackageReferenceRemover;
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
        {--keep-tokens : Leave the magic_login_tokens table in place}
        {--drop-tokens : Drop the magic_login_tokens table without asking}
        {--keep-code : Do not edit panel providers or login pages}';

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly PackageReferenceRemover $remover,
    ) {
        parent::__construct();

        $this->setDescription(__('filament-magic-login::filament-magic-login.uninstall.description'));
    }

    public function handle(): int
    {
        if (! $this->confirmToProceed()) {
            $this->comment(__('filament-magic-login::filament-magic-login.uninstall.aborted'));

            return static::SUCCESS;
        }

        $this->cleanSourceFiles();
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
     * Strips the plugin registration and the trait out of the application's own code.
     * Without this, removing the Composer package would leave the panel provider
     * referencing a class that no longer exists, and every request would fail.
     */
    protected function cleanSourceFiles(): void
    {
        if ($this->option('keep-code')) {
            $this->reportRegisteredPanels();

            return;
        }

        foreach ($this->sourceFiles() as $path) {
            $original = $this->filesystem->get($path);
            $result = $this->remover->remove($original);

            if ($result->changed) {
                $this->filesystem->put($path, $result->code);

                $this->info(__('filament-magic-login::filament-magic-login.uninstall.code_updated', [
                    'path' => $this->relative($path),
                ]));
            }

            if ($result->isClean()) {
                continue;
            }

            $this->warn(__('filament-magic-login::filament-magic-login.uninstall.code_manual', [
                'path' => $this->relative($path),
                'lines' => implode(', ', $result->unresolvedLines),
            ]));
        }
    }

    /**
     * Application PHP files that mention the package at all.
     *
     * @return array<int, string>
     */
    protected function sourceFiles(): array
    {
        $roots = array_filter(
            [app_path(), base_path('bootstrap'), base_path('routes')],
            fn (string $path): bool => $this->filesystem->isDirectory($path),
        );

        $files = [];

        foreach ($roots as $root) {
            foreach ($this->filesystem->allFiles($root) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                if (! str_contains((string) $this->filesystem->get($file->getRealPath()), PackageReferenceRemover::NAMESPACE)) {
                    continue;
                }

                $files[] = $file->getRealPath();
            }
        }

        return $files;
    }

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

    protected function relative(string $path): string
    {
        return str_starts_with($path, base_path())
            ? ltrim(substr($path, strlen(base_path())), DIRECTORY_SEPARATOR)
            : $path;
    }

    protected function dropTokensTable(): void
    {
        $table = (string) config('filament-magic-login.storage.table', 'magic_login_tokens');

        if (config('filament-magic-login.storage.driver') === 'cache') {
            $this->comment(__('filament-magic-login::filament-magic-login.uninstall.cache_note'));
        }

        if ($this->option('keep-tokens')) {
            $this->keepTable($table);

            return;
        }

        if (! Schema::hasTable($table)) {
            $this->comment(__('filament-magic-login::filament-magic-login.uninstall.table_missing', ['table' => $table]));

            return;
        }

        if (! $this->shouldDropTable($table)) {
            $this->keepTable($table);

            return;
        }

        Schema::drop($table);

        $this->info(__('filament-magic-login::filament-magic-login.uninstall.table_dropped', ['table' => $table]));
    }

    protected function shouldDropTable(string $table): bool
    {
        if ($this->option('force') || $this->option('drop-tokens')) {
            return true;
        }

        return $this->confirm(
            __('filament-magic-login::filament-magic-login.uninstall.drop_table', ['table' => $table]),
            false,
        );
    }

    /**
     * Says so, and says what to run to change its mind: a table left behind is what a
     * later `install` trips over, and the reason is worth knowing before then.
     */
    protected function keepTable(string $table): void
    {
        $this->comment(__('filament-magic-login::filament-magic-login.uninstall.table_kept', ['table' => $table]));
        $this->comment(__('filament-magic-login::filament-magic-login.uninstall.table_kept_hint', [
            'command' => $this->getName().' --drop-tokens',
        ]));
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
