<?php

namespace Arzcode\FilamentMagicLogin;

use Arzcode\FilamentMagicLogin\Contracts\TokenRepository;
use Arzcode\FilamentMagicLogin\Repositories\CacheTokenRepository;
use Arzcode\FilamentMagicLogin\Repositories\DatabaseTokenRepository;
use Arzcode\FilamentMagicLogin\Support\TokenGenerator;
use Arzcode\FilamentMagicLogin\Support\UserProviderResolver;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use LogicException;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FilamentMagicLoginServiceProvider extends PackageServiceProvider
{
    public const DRIVER_DATABASE = 'database';

    public const DRIVER_CACHE = 'cache';

    public function configurePackage(Package $package): void
    {
        $package
            ->name('filament-magic-login')
            ->hasConfigFile()
            ->hasMigration('create_magic_login_tokens_table')
            ->hasTranslations()
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command
                    ->publishConfigFile()
                    // The storage driver is read when the command runs, not while the
                    // package is being configured: config is not merged yet at that point.
                    ->startWith(function (InstallCommand $command): void {
                        if (config('filament-magic-login.storage.driver') === static::DRIVER_CACHE) {
                            $command->comment(__('filament-magic-login::filament-magic-login.install.cache_driver_skip_migrations'));

                            return;
                        }

                        $command->publishMigrations()->askToRunMigrations();
                    })
                    ->askToStarRepoOnGitHub('arzcode/filament-magic-login');
            });
    }

    public function packageRegistered(): void
    {
        // Panels are registered while Filament boots, which can happen before this
        // provider boots. Loading the namespace here keeps the LogicException we may
        // throw during panel registration readable.
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'filament-magic-login');

        $this->app->singleton(TokenGenerator::class, fn (): TokenGenerator => new TokenGenerator);
        $this->app->singleton(UserProviderResolver::class, fn (): UserProviderResolver => new UserProviderResolver);

        $this->app->scoped(TokenRepository::class, function (): TokenRepository {
            $driver = config('filament-magic-login.storage.driver', static::DRIVER_DATABASE);

            return match ($driver) {
                static::DRIVER_DATABASE => $this->app->make(DatabaseTokenRepository::class),
                static::DRIVER_CACHE => $this->guardCacheStore($this->app->make(CacheTokenRepository::class)),
                default => throw new LogicException(__('filament-magic-login::filament-magic-login.exceptions.unknown_storage_driver', [
                    'driver' => is_string($driver) ? $driver : gettype($driver),
                ])),
            };
        });
    }

    public function packageBooted(): void
    {
        $this->registerRateLimiters();
    }

    /**
     * The request limiter is applied by hand inside SendMagicLink; only the consume
     * route uses the named middleware, keyed by IP.
     */
    protected function registerRateLimiters(): void
    {
        RateLimiter::for('filament-magic-login-consume', function (Request $request): Limit {
            $panel = filament()->getCurrentPanel();

            $plugin = ($panel !== null && $panel->hasPlugin(MagicLoginPlugin::ID))
                ? MagicLoginPlugin::for($panel)
                : null;

            $maxAttempts = $plugin?->getConsumeRateLimitMaxAttempts()
                ?? config('filament-magic-login.consume_rate_limit.max_attempts', 10);

            $decaySeconds = $plugin?->getConsumeRateLimitDecaySeconds()
                ?? config('filament-magic-login.consume_rate_limit.decay_seconds', 60);

            return Limit::perSecond($maxAttempts, $decaySeconds)->by((string) $request->ip());
        });
    }

    protected function guardCacheStore(CacheTokenRepository $repository): CacheTokenRepository
    {
        if (! $this->app->environment('production')) {
            return $repository;
        }

        $store = $repository->store();

        if ($store instanceof CacheRepository && $this->isUnsafeStore($store)) {
            throw new LogicException(__('filament-magic-login::filament-magic-login.exceptions.unsafe_cache_store', [
                'store' => config('filament-magic-login.storage.cache_store') ?? config('cache.default'),
            ]));
        }

        return $repository;
    }

    protected function isUnsafeStore(CacheRepository $repository): bool
    {
        $store = $repository->getStore();

        return $store instanceof ArrayStore || $store instanceof FileStore;
    }
}
