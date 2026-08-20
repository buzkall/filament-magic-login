<?php

namespace Arzcode\FilamentMagicLogin;

use Arzcode\FilamentMagicLogin\Concerns\HasMagicLinkAction;
use Arzcode\FilamentMagicLogin\Contracts\MagicLinkNotification as MagicLinkNotificationContract;
use Arzcode\FilamentMagicLogin\Enums\MagicLinkPosition;
use Arzcode\FilamentMagicLogin\Http\Controllers\ConsumeMagicLinkController;
use Arzcode\FilamentMagicLogin\Pages\Login;
use Closure;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\PanelRegistry;
use Filament\Support\Concerns\EvaluatesClosures;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;
use Livewire\Finder\Finder;
use Livewire\Livewire;
use LogicException;

class MagicLoginPlugin implements Plugin
{
    use EvaluatesClosures;

    public const ID = 'magic-login';

    protected int|Closure|null $expiresAfterMinutes = null;

    protected MagicLinkPosition|Closure|null $position = null;

    protected string|Closure|null $label = null;

    /** @var class-string<MagicLinkNotificationContract>|Closure|null */
    protected string|Closure|null $notification = null;

    protected int|Closure|null $rateLimitMaxAttempts = null;

    protected int|Closure|null $rateLimitDecaySeconds = null;

    protected int|Closure|null $consumeRateLimitMaxAttempts = null;

    protected int|Closure|null $consumeRateLimitDecaySeconds = null;

    protected string|Closure|null $redirectTo = null;

    protected string|Closure|null $routePath = null;

    protected bool|Closure|null $invalidatePrevious = null;

    protected bool|Closure|null $honorRemember = null;

    protected bool|Closure $usesCustomLoginPage = false;

    public static function make(): static
    {
        return app(static::class);
    }

    /**
     * The plugin registered on the current panel.
     */
    public static function get(): static
    {
        /** @var static $plugin */
        $plugin = Filament::getCurrentOrDefaultPanel()->getPlugin(static::ID);

        return $plugin;
    }

    public static function for(Panel $panel): static
    {
        /** @var static $plugin */
        $plugin = $panel->getPlugin(static::ID);

        return $plugin;
    }

    public function getId(): string
    {
        return static::ID;
    }

    public function register(Panel $panel): void
    {
        $panel->routes(function (Panel $panel): void {
            Route::get($this->getRoutePath().'/{token}', ConsumeMagicLinkController::class)
                ->middleware([
                    'guest:'.$panel->getAuthGuard(),
                    'throttle:filament-magic-login-consume',
                ])
                ->name('magic-login.consume');
        });

        $this->configureLoginPage($panel);

        // The panel configuration chain may call `->login()` after `->plugin()`, so the
        // detection is repeated once every panel has finished configuring itself. The
        // container fires this before Filament's route file reads the login page class.
        app()->afterResolving(PanelRegistry::class, function () use ($panel): void {
            $this->configureLoginPage($panel);
        });
    }

    public function boot(Panel $panel): void {}

    public function expiresAfter(int|Closure $minutes): static
    {
        $this->expiresAfterMinutes = $minutes;

        return $this;
    }

    public function position(MagicLinkPosition|Closure $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function label(string|Closure|null $label): static
    {
        $this->label = $label;

        return $this;
    }

    /**
     * @param  class-string<MagicLinkNotificationContract>|Closure  $notification
     */
    public function notification(string|Closure $notification): static
    {
        $this->notification = $notification;

        return $this;
    }

    public function rateLimit(int|Closure $maxAttempts, int|Closure $decaySeconds): static
    {
        $this->rateLimitMaxAttempts = $maxAttempts;
        $this->rateLimitDecaySeconds = $decaySeconds;

        return $this;
    }

    public function consumeRateLimit(int|Closure $maxAttempts, int|Closure $decaySeconds): static
    {
        $this->consumeRateLimitMaxAttempts = $maxAttempts;
        $this->consumeRateLimitDecaySeconds = $decaySeconds;

        return $this;
    }

    public function redirectTo(string|Closure|null $url): static
    {
        $this->redirectTo = $url;

        return $this;
    }

    public function routePath(string|Closure $path): static
    {
        $this->routePath = $path;

        return $this;
    }

    public function invalidatePrevious(bool|Closure $condition = true): static
    {
        $this->invalidatePrevious = $condition;

        return $this;
    }

    public function honorRemember(bool|Closure $condition = true): static
    {
        $this->honorRemember = $condition;

        return $this;
    }

    /**
     * Skip login page detection entirely: the panel's own login page is left alone.
     */
    public function useCustomLoginPage(bool|Closure $condition = true): static
    {
        $this->usesCustomLoginPage = $condition;

        return $this;
    }

    public function getExpiresAfterMinutes(): int
    {
        return (int) ($this->evaluate($this->expiresAfterMinutes)
            ?? config('filament-magic-login.expires_after_minutes', 15));
    }

    public function getPosition(): MagicLinkPosition
    {
        $position = $this->evaluate($this->position)
            ?? config('filament-magic-login.position', MagicLinkPosition::BelowForm);

        return $position instanceof MagicLinkPosition ? $position : MagicLinkPosition::BelowForm;
    }

    public function getLabel(): string
    {
        return (string) ($this->evaluate($this->label)
            ?? __('filament-magic-login::filament-magic-login.actions.magic_link'));
    }

    public function getTooltip(): string
    {
        return __('filament-magic-login::filament-magic-login.actions.magic_link_tooltip');
    }

    /**
     * @return class-string<MagicLinkNotificationContract>
     */
    public function getNotificationClass(): string
    {
        /** @var class-string<MagicLinkNotificationContract> $class */
        $class = $this->evaluate($this->notification)
            ?? config('filament-magic-login.notification');

        return $class;
    }

    public function getRateLimitMaxAttempts(): int
    {
        return (int) ($this->evaluate($this->rateLimitMaxAttempts)
            ?? config('filament-magic-login.rate_limit.max_attempts', 3));
    }

    public function getRateLimitDecaySeconds(): int
    {
        return (int) ($this->evaluate($this->rateLimitDecaySeconds)
            ?? config('filament-magic-login.rate_limit.decay_seconds', 300));
    }

    public function getConsumeRateLimitMaxAttempts(): int
    {
        return (int) ($this->evaluate($this->consumeRateLimitMaxAttempts)
            ?? config('filament-magic-login.consume_rate_limit.max_attempts', 10));
    }

    public function getConsumeRateLimitDecaySeconds(): int
    {
        return (int) ($this->evaluate($this->consumeRateLimitDecaySeconds)
            ?? config('filament-magic-login.consume_rate_limit.decay_seconds', 60));
    }

    public function getRedirectUrl(?Authenticatable $user = null): ?string
    {
        $url = $this->evaluate($this->redirectTo, namedInjections: ['user' => $user]);

        return filled($url) ? (string) $url : null;
    }

    public function getRoutePath(): string
    {
        return trim((string) ($this->evaluate($this->routePath)
            ?? config('filament-magic-login.route_path', 'magic-login')), '/');
    }

    public function shouldInvalidatePrevious(): bool
    {
        return (bool) ($this->evaluate($this->invalidatePrevious)
            ?? config('filament-magic-login.invalidate_previous', true));
    }

    public function shouldHonorRemember(): bool
    {
        return (bool) ($this->evaluate($this->honorRemember)
            ?? config('filament-magic-login.honor_remember', true));
    }

    public function usesCustomLoginPage(): bool
    {
        return (bool) $this->evaluate($this->usesCustomLoginPage);
    }

    /**
     * Swaps Filament's stock login page for ours, so a plain `->login()` panel needs
     * no files in the host app. A custom page is never silently replaced.
     */
    protected function configureLoginPage(Panel $panel): void
    {
        if ($this->usesCustomLoginPage()) {
            return;
        }

        $action = $panel->getLoginRouteAction();

        // No login page configured (yet), or a closure/controller the panel owns.
        if (! is_string($action)) {
            return;
        }

        if ($action === Login::class) {
            return;
        }

        if ($action === BaseLogin::class) {
            $panel->login(Login::class);

            $this->registerLivewireComponent(Login::class);

            return;
        }

        if (in_array(HasMagicLinkAction::class, class_uses_recursive($action), true)) {
            return;
        }

        throw new LogicException(__('filament-magic-login::filament-magic-login.exceptions.custom_login_without_trait', [
            'panel' => $panel->getId(),
            'class' => $action,
            'trait' => HasMagicLinkAction::class,
        ]));
    }

    /**
     * @param  class-string  $component
     */
    protected function registerLivewireComponent(string $component): void
    {
        [, $name] = app(Finder::class)->parseNamespaceAndName($component);

        Livewire::component($name, $component);
    }
}
