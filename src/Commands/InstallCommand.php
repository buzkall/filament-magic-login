<?php

namespace Arzcode\FilamentMagicLogin\Commands;

use Arzcode\FilamentMagicLogin\FilamentMagicLoginServiceProvider;
use Arzcode\FilamentMagicLogin\MagicLoginPlugin;
use Arzcode\FilamentMagicLogin\Support\PluginRegistrationWriter;
use Arzcode\FilamentMagicLogin\Support\ScheduleWriter;
use Arzcode\FilamentMagicLogin\Support\UserResourceWriter;
use Filament\Facades\Filament;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\warning;

/**
 * Publishes what the package needs and offers to wire up the parts an application
 * has to opt into: the migration, the pruner for the rows it leaves behind, and the
 * plugin registration that puts the action on a panel's login page.
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
        private readonly PluginRegistrationWriter $registration,
        private readonly UserResourceWriter $resources,
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
        } else {
            $this->publishMigrations();
            $this->runMigrations();
            $this->schedulePruning();
        }

        // Last, because it is the step that makes the action appear: everything the
        // panel needs is in place by the time the plugin is registered.
        $this->registerPlugin();

        // Last of all, because it is the only step that needs the plugin already on a
        // panel: the action reads its settings from one.
        $this->addResourceActions();

        outro(__('filament-magic-login::filament-magic-login.install.done'));

        return static::SUCCESS;
    }

    protected function usesCacheDriver(): bool
    {
        return config('filament-magic-login.storage.driver') === FilamentMagicLoginServiceProvider::DRIVER_CACHE;
    }

    /**
     * The config file is a convenience, not a requirement: the provider merges the
     * package defaults either way, so this asks rather than assumes.
     */
    protected function publishConfig(): void
    {
        $published = $this->filesystem->exists(config_path('filament-magic-login.php'));

        // The one pair of questions that defaults to no. Every option has a working
        // default in the package's own config, so publishing the file is the step an
        // application only wants when it means to edit it — and overwriting one it has
        // already edited is the destructive answer besides.
        $confirmed = $published
            ? confirm(
                label: __('filament-magic-login::filament-magic-login.install.config_overwrite'),
                default: false,
            )
            : confirm(
                label: __('filament-magic-login::filament-magic-login.install.config_publish'),
                default: false,
            );

        if (! $confirmed) {
            note(__($published
                ? 'filament-magic-login::filament-magic-login.install.config_kept'
                : 'filament-magic-login::filament-magic-login.install.config_skipped'));

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

        // Yes, like every other wiring step: an installer is asked to install, and each
        // rewrite is reported and reversible by `filament-magic-login:uninstall`. With no
        // terminal to ask, Prompts answers with the default, so an unattended install
        // wires the package up rather than leaving it half-installed.
        if (! confirm(
            label: __('filament-magic-login::filament-magic-login.install.schedule_prompt'),
            default: true,
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

    /**
     * Nothing else in the install matters until the plugin is on a panel: without it
     * the table is created, the pruner is scheduled, and no login page ever changes.
     *
     * Whether it is registered is read from the application's own source rather than
     * from the booted panels, because the source is what this command can act on.
     */
    protected function registerPlugin(): void
    {
        $providers = [];

        foreach ($this->applicationFiles() as $path) {
            $code = (string) $this->filesystem->get($path);

            if ($this->registration->isRegistered($code)) {
                note(__('filament-magic-login::filament-magic-login.install.plugin_registered', [
                    'path' => $this->relative($path),
                ]));

                return;
            }

            if ($this->registration->isPanelProvider($code)) {
                $providers[] = $path;
            }
        }

        if ($providers === [] || ! $this->registerPluginIn($providers)) {
            $this->explainRegistrationByHand();
        }
    }

    /**
     * @param  array<int, string>  $providers
     * @return bool Whether any provider was actually edited.
     */
    protected function registerPluginIn(array $providers): bool
    {
        $registered = false;

        foreach ($providers as $path) {
            if (! confirm(
                label: __('filament-magic-login::filament-magic-login.install.plugin_prompt', [
                    'path' => $this->relative($path),
                ]),
                default: true,
            )) {
                continue;
            }

            $result = $this->registration->add((string) $this->filesystem->get($path));

            if ($result === null) {
                warning(__('filament-magic-login::filament-magic-login.install.plugin_failed', [
                    'path' => $this->relative($path),
                ]));

                continue;
            }

            $this->filesystem->put($path, $result);

            note(__('filament-magic-login::filament-magic-login.install.plugin_added', [
                'path' => $this->relative($path),
            ]));

            $registered = true;
        }

        return $registered;
    }

    protected function explainRegistrationByHand(): void
    {
        note(__('filament-magic-login::filament-magic-login.install.plugin_manual'));

        $this->line($this->registration->snippet());
    }

    /**
     * Offers to put the "send a login link" action on the application's own user
     * resource — its table, and the header of each record page.
     *
     * Stays completely silent when the application has no Filament resources at all,
     * so an installation that never had any is not told about a step it cannot take.
     */
    protected function addResourceActions(): void
    {
        $model = $this->userModel();

        if ($model === null) {
            return;
        }

        $files = [];
        $isFilamentApplication = false;

        foreach ($this->applicationFiles() as $path) {
            $code = (string) $this->filesystem->get($path);
            $files[$path] = $code;

            $isFilamentApplication = $isFilamentApplication || str_contains($code, 'extends Resource');
        }

        if (! $isFilamentApplication) {
            return;
        }

        $resources = array_keys(array_filter(
            $files,
            fn (string $code): bool => $this->resources->isResourceFor($code, $model),
        ));

        // None, or more than one, is a choice we have no business making for somebody.
        if (count($resources) !== 1) {
            note(__('filament-magic-login::filament-magic-login.install.resource_missing', [
                'model' => $model,
            ]));

            $this->explainResourceByHand();

            return;
        }

        $resource = $resources[0];
        $resourceClass = $this->resources->declaredClass($files[$resource]);

        $targets = array_filter([
            $this->tableFile($resource, $files),
            ...($resourceClass === null ? [] : $this->recordPages($resourceClass, $files)),
        ]);

        if ($targets === [] || ! $this->wireResourceActions($targets, $files)) {
            $this->explainResourceByHand();
        }
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $targets  Path and the method to append with.
     * @param  array<string, string>  $files
     * @return bool Whether any file was actually edited.
     */
    protected function wireResourceActions(array $targets, array $files): bool
    {
        $wired = false;

        foreach ($targets as [$path, $method]) {
            if ($this->resources->isWired($files[$path])) {
                note(__('filament-magic-login::filament-magic-login.install.resource_exists', [
                    'path' => $this->relative($path),
                ]));

                $wired = true;

                continue;
            }

            if (! confirm(
                label: __('filament-magic-login::filament-magic-login.install.resource_prompt', [
                    'path' => $this->relative($path),
                ]),
                default: true,
            )) {
                continue;
            }

            $result = $this->resources->{$method}($files[$path]);

            if ($result === null) {
                warning(__('filament-magic-login::filament-magic-login.install.resource_failed', [
                    'path' => $this->relative($path),
                ]));

                continue;
            }

            $this->filesystem->put($path, $result);

            note(__('filament-magic-login::filament-magic-login.install.resource_added', [
                'path' => $this->relative($path),
            ]));

            $wired = true;
        }

        return $wired;
    }

    /**
     * The file holding the resource's table: the resource itself when the table is
     * written inline, or the extracted table class it hands off to.
     *
     * @param  array<string, string>  $files
     * @return array{0: string, 1: string}|null
     */
    protected function tableFile(string $resource, array $files): ?array
    {
        if ($this->resources->hasRecordActions($files[$resource])) {
            return [$resource, 'addRecordAction'];
        }

        $imported = $this->resources->importedClasses($files[$resource]);

        $candidates = array_keys(array_filter(
            $files,
            fn (string $code, string $path): bool => $path !== $resource
                && $this->resources->hasRecordActions($code)
                && in_array($this->resources->declaredClass($code), $imported, true),
            ARRAY_FILTER_USE_BOTH,
        ));

        return count($candidates) === 1 ? [$candidates[0], 'addRecordAction'] : null;
    }

    /**
     * @param  array<string, string>  $files
     * @return array<int, array{0: string, 1: string}>
     */
    protected function recordPages(string $resourceClass, array $files): array
    {
        $pages = array_keys(array_filter(
            $files,
            fn (string $code): bool => $this->resources->isRecordPageFor($code, $resourceClass),
        ));

        return array_map(fn (string $path): array => [$path, 'addHeaderAction'], $pages);
    }

    /**
     * The model the panels this plugin serves authenticate, so the right resource can
     * be picked out of an application that has many.
     */
    protected function userModel(): ?string
    {
        $guards = [];

        foreach (Filament::getPanels() as $panel) {
            if ($panel->hasPlugin(MagicLoginPlugin::ID)) {
                $guards[] = $panel->getAuthGuard();
            }
        }

        $guards[] = config('auth.defaults.guard');

        foreach (array_filter($guards) as $guard) {
            $provider = config("auth.guards.{$guard}.provider");
            $model = is_string($provider) ? config("auth.providers.{$provider}.model") : null;

            if (is_string($model) && class_exists($model)) {
                return $model;
            }
        }

        $model = config('auth.providers.users.model');

        return (is_string($model) && class_exists($model)) ? $model : null;
    }

    protected function explainResourceByHand(): void
    {
        note(__('filament-magic-login::filament-magic-login.install.resource_manual'));

        $this->line($this->resources->snippet());
    }

    /**
     * The application's own PHP files, which are the only ones this command reads or
     * edits. Mirrors the roots the uninstall command cleans.
     *
     * @return array<int, string>
     */
    protected function applicationFiles(): array
    {
        $roots = array_filter(
            [app_path(), base_path('bootstrap'), base_path('routes')],
            fn (string $path): bool => $this->filesystem->isDirectory($path),
        );

        $files = [];

        foreach ($roots as $root) {
            foreach ($this->filesystem->allFiles($root) as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getRealPath();
                }
            }
        }

        return $files;
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
