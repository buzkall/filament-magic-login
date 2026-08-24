<?php

namespace Arzcode\FilamentMagicLogin\Commands;

use Arzcode\FilamentMagicLogin\FilamentMagicLoginServiceProvider;
use Arzcode\FilamentMagicLogin\Support\ScheduleWriter;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\warning;

/**
 * Publishes what the package needs and offers to wire up the parts an application
 * has to opt into: the migration, and the pruner for the rows it leaves behind.
 *
 * Hand-rolled rather than built with spatie's InstallCommand so the whole run reads
 * in one voice — every question goes through Laravel Prompts.
 */
class InstallCommand extends Command
{
    protected $signature = 'filament-magic-login:install';

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly ScheduleWriter $writer,
    ) {
        parent::__construct();

        $this->setDescription(__('filament-magic-login::filament-magic-login.install.description'));
    }

    public function handle(): int
    {
        intro(__('filament-magic-login::filament-magic-login.install.intro'));

        $this->publishConfig();

        if ($this->usesCacheDriver()) {
            // Cached links carry their own TTL, so there is no table and nothing to prune.
            note(__('filament-magic-login::filament-magic-login.install.cache_driver_skip_migrations'));

            outro(__('filament-magic-login::filament-magic-login.install.done'));

            return static::SUCCESS;
        }

        $this->publishMigrations();
        $this->runMigrations();
        $this->schedulePruning();

        outro(__('filament-magic-login::filament-magic-login.install.done'));

        return static::SUCCESS;
    }

    protected function usesCacheDriver(): bool
    {
        return config('filament-magic-login.storage.driver') === FilamentMagicLoginServiceProvider::DRIVER_CACHE;
    }

    protected function publishConfig(): void
    {
        $published = $this->filesystem->exists(config_path('filament-magic-login.php'));

        if ($published && ! confirm(
            label: __('filament-magic-login::filament-magic-login.install.config_overwrite'),
            default: false,
        )) {
            note(__('filament-magic-login::filament-magic-login.install.config_kept'));

            return;
        }

        $this->publish('filament-magic-login-config', $published);

        note(__('filament-magic-login::filament-magic-login.install.config_published'));
    }

    protected function publishMigrations(): void
    {
        if ($this->publishedMigrations() !== []) {
            note(__('filament-magic-login::filament-magic-login.install.migrations_kept'));

            return;
        }

        $this->publish('filament-magic-login-migrations');

        note(__('filament-magic-login::filament-magic-login.install.migrations_published'));
    }

    protected function runMigrations(): void
    {
        if (! confirm(__('filament-magic-login::filament-magic-login.install.run_migrations'))) {
            note(__('filament-magic-login::filament-magic-login.install.migrations_skipped'));

            return;
        }

        $this->callSilently('migrate');

        note(__('filament-magic-login::filament-magic-login.install.migrations_ran'));
    }

    /**
     * Rows linger for a day after they expire so they can be audited; without the
     * pruner scheduled, that day never ends and the table only grows.
     */
    protected function schedulePruning(): void
    {
        $path = base_path('routes/console.php');

        if (! $this->filesystem->exists($path)) {
            $this->explainScheduleByHand();

            return;
        }

        $code = $this->filesystem->get($path);

        if ($this->writer->isScheduled($code)) {
            note(__('filament-magic-login::filament-magic-login.install.schedule_exists'));

            return;
        }

        // Defaulting to "no" is what keeps an unattended install from rewriting somebody's
        // console routes: with no terminal to ask, Prompts answers with the default.
        if (! confirm(
            label: __('filament-magic-login::filament-magic-login.install.schedule_prompt'),
            default: false,
        )) {
            $this->explainScheduleByHand();

            return;
        }

        $scheduled = $this->writer->add($code);

        if ($scheduled === null) {
            warning(__('filament-magic-login::filament-magic-login.install.schedule_failed', [
                'path' => $this->relative($path),
            ]));

            $this->explainScheduleByHand();

            return;
        }

        $this->filesystem->put($path, $scheduled);

        note(__('filament-magic-login::filament-magic-login.install.schedule_added', [
            'path' => $this->relative($path),
        ]));
    }

    protected function explainScheduleByHand(): void
    {
        note(__('filament-magic-login::filament-magic-login.install.schedule_manual'));

        $this->line($this->writer->snippet());
    }

    protected function publish(string $tag, bool $force = false): void
    {
        $this->callSilently('vendor:publish', array_filter([
            '--tag' => $tag,
            '--force' => $force,
        ]));
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

    protected function relative(string $path): string
    {
        return str_starts_with($path, base_path())
            ? ltrim(substr($path, strlen(base_path())), DIRECTORY_SEPARATOR)
            : $path;
    }
}
