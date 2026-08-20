# filament-magic-login — Implementation Spec

Passwordless "email me a login link" option for Filament panels, added **alongside** the standard email/password login form. Reusable package, multi-panel aware, single-use tokens, Pest-tested.

This document is the single source of truth for implementation. Follow it section by section. Where a decision is marked **DECIDED**, do not revisit it. Where marked **OPEN**, pick the simplest option and note it in the README.

---

## 0. Identity

| Item | Value |
|---|---|
| Packagist name | `arzcode/filament-magic-login` |
| Namespace | `Arzcode\FilamentMagicLogin` |
| Config key / file | `filament-magic-login` |
| Translation namespace | `filament-magic-login` |
| View namespace | `filament-magic-login` |
| Migration table | `magic_login_tokens` |
| License | MIT |
| Min PHP | 8.3 |
| Laravel | `^12.0 \|\| ^13.0` |
| Filament | `^5.0` (keep `^4.0` only if it costs nothing; do not contort code for it) |
| Livewire | `^3.0` (whatever Filament 5 pins) |
| Base scaffolding | `spatie/laravel-package-tools` |
| Testing | Pest 5 (`pestphp/pest: ^5.0`, `pestphp/pest-plugin-laravel: ^5.0`, `pestphp/pest-plugin-livewire: ^5.0`) + `orchestra/testbench` + `livewire/livewire` test helpers |
| Static analysis | Larastan level 6, Laravel Pint (preset `laravel`) |

---

## 1. Goals & non-goals

### Goals
1. Add a "Email me a login link" action to the **existing** Filament panel login page. Password login keeps working unchanged.
2. Email-only delivery via a Laravel `Notification` (queueable).
3. Only **existing** users can request a link. No auto-registration.
4. Tokens are **hashed at rest**, **single-use**, **time-limited**, **bound to a panel** (and guard).
5. Works with multiple panels, each with its own config overrides.
6. Respects `FilamentUser::canAccessPanel()`.
7. No user enumeration: identical UI response whether or not the email exists.
8. Rate-limited request endpoint.
9. Zero Blade view overriding of Filament internals. Use the public Login page extension points and render hooks only.
10. Full Pest coverage, Larastan-clean, Pint-clean.
11. **Every user-facing string is translatable** (action labels, tooltips, notifications, validation messages, mail subject/body, exception messages surfaced to users, install command prompts). No hard-coded English anywhere outside `resources/lang/en`. Shipped languages: `en`, `es` (es-ES), `ca`. A Pest test asserts the three files have identical key sets.

### Non-goals
- SMS / push / OTP codes.
- Registration via magic link.
- Replacing the login page entirely (could be a later `->replacesPasswordLogin()` flag — **not in v1**).
- Two-factor interplay beyond "after magic-link login, Filament's own MFA flow runs as normal" (verify this in a test; if Filament 5's MFA middleware intercepts post-login it should Just Work since we call the same `Filament::auth()->login()`).

---

## 2. Architecture overview

```
arzcode/filament-magic-login
├── composer.json
├── config/filament-magic-login.php
├── database/migrations/create_magic_login_tokens_table.php.stub
├── resources/
│   ├── lang/en/filament-magic-login.php
│   ├── lang/es/filament-magic-login.php
│   ├── lang/ca/filament-magic-login.php
│   └── views/ (only if strictly needed — prefer none)
├── routes/ (none — routes are registered per panel in the Plugin)
├── src/
│   ├── FilamentMagicLoginServiceProvider.php
│   ├── MagicLoginPlugin.php                     # Filament\Contracts\Plugin
│   ├── Enums/MagicLinkPosition.php
│   ├── Contracts/TokenRepository.php
│   ├── Repositories/DatabaseTokenRepository.php
│   ├── Repositories/CacheTokenRepository.php
│   ├── Data/MagicLinkToken.php                  # readonly DTO returned by repositories
│   ├── Models/MagicLoginToken.php               # Eloquent model, database driver only
│   ├── Actions/                                 # single-purpose invokable classes (domain "actions", not Filament actions)
│   │   ├── SendMagicLink.php
│   │   └── ConsumeMagicLink.php
│   ├── Http/Controllers/ConsumeMagicLinkController.php
│   ├── Notifications/MagicLinkNotification.php
│   ├── Pages/Login.php                          # extends Filament\Auth\Pages\Login
│   ├── Concerns/HasMagicLinkAction.php          # trait for projects with their own Login page
│   ├── Events/MagicLinkRequested.php
│   ├── Events/MagicLinkConsumed.php
│   ├── Events/MagicLinkRejected.php
│   ├── Exceptions/InvalidMagicLinkException.php
│   ├── Support/TokenGenerator.php
│   └── Commands/PruneMagicLoginTokensCommand.php (only if Prunable isn't enough — prefer Prunable)
└── tests/
    ├── Pest.php
    ├── TestCase.php
    ├── Fixtures/ (User model, PanelProvider, migrations)
    ├── Unit/
    └── Feature/
```

**DECIDED: token storage behind a `TokenRepository` contract with two drivers — `database` (default) and `cache`.** The package **never modifies the host app's `users` table**; the database driver adds its own `magic_login_tokens` table, the cache driver adds nothing at all.

Why not reuse `users.remember_token`? Rejected, do not revisit:
- It's a **long-lived, plaintext** credential that Laravel's `remember_me` cookie already depends on. Putting it in an email makes the email a permanent session key.
- It has **no expiry, no used_at, no panel/guard binding**. Single-use would require rotating it, which silently logs the user out of every remembered device.
- One value per user → can't have concurrent links, can't audit, can't tell "expired" from "used" from "never issued".
- Laravel's own `password_reset_tokens` is the right precedent: a separate, email-keyed, bcrypt-hashed table with a TTL. We follow that pattern, but with more columns because we need panel binding and single-use semantics.

The `cache` driver exists for projects that refuse any migration: it stores `hash → payload` with TTL = link lifetime and marks consumption via atomic `Cache::lock()`/`pull()`. Trade-offs documented in README: no audit trail, `array`/`file` cache drivers are unsuitable in multi-process deployments (Redis/Memcached/Database cache only), and `invalidate_previous` requires a secondary `user:{id}:{panel}` index key.

**DECIDED: integration via a Login page subclass + an opt-in trait**, not a standalone Livewire component in a render hook. The action must read the email already typed into the login form, which requires being on the same Livewire component.

---

## 3. Token storage

### Contract `Contracts\TokenRepository`

```php
interface TokenRepository
{
    /** Store a new token; returns the stored record. */
    public function create(Authenticatable $user, string $hash, string $panelId, string $guard, bool $remember, CarbonImmutable $expiresAt, ?string $ip, ?string $userAgent): MagicLinkToken;

    /** Find by hash + panel. Null if unknown. */
    public function find(string $hash, string $panelId): ?MagicLinkToken;

    /** Atomically mark as used. Returns false if already used / gone (race lost). */
    public function consume(MagicLinkToken $token): bool;

    /** Delete the user's unused tokens for a panel. */
    public function invalidateFor(Authenticatable $user, string $panelId): void;
}
```

### DTO `Data\MagicLinkToken`

```php
final readonly class MagicLinkToken
{
    public function __construct(
        public string $id,                 // DB id or cache key
        public string $authenticatableType,
        public int|string $authenticatableId,
        public string $hash,
        public string $panelId,
        public string $guard,
        public bool $remember,
        public CarbonImmutable $expiresAt,
        public ?CarbonImmutable $usedAt,
    ) {}

    public function isExpired(): bool;
    public function isUsed(): bool;
    public function resolveUser(): ?Authenticatable;   // via the guard's provider, never a hard-coded model
}
```

Domain actions (§7) depend only on the contract. The provider binds the driver from config `storage.driver`.

### Driver `database` (default) — migration `create_magic_login_tokens_table`

```php
Schema::create('magic_login_tokens', function (Blueprint $table) {
    $table->id();
    $table->morphs('authenticatable');          // authenticatable_type, authenticatable_id
    $table->string('token_hash', 64)->unique();  // sha256 hex
    $table->string('panel_id', 64)->index();
    $table->string('guard', 64);
    $table->boolean('remember')->default(false);
    $table->string('requested_ip', 45)->nullable();
    $table->string('requested_user_agent', 512)->nullable();
    $table->timestamp('expires_at')->index();
    $table->timestamp('used_at')->nullable();
    $table->timestamps();
});
```

### Model `MagicLoginToken` (database driver only)

- `use Prunable;` — `prunable()` returns `where('expires_at', '<', now()->subDay())` (keep a day of used/expired rows for audit, then drop).
- Casts: `expires_at`, `used_at` → `immutable_datetime`, `remember` → `bool`.
- `$guarded = []`.
- `authenticatable(): MorphTo`.
- Scopes: `scopeUnused`, `scopeUnexpired`, `scopeForPanel(string $panelId)`.
- Helpers: `isExpired(): bool`, `isUsed(): bool`, `isValid(): bool`, `markUsed(): void`.
- Table name from config `filament-magic-login.storage.table` (default `magic_login_tokens`).
- Never store the plaintext token.
- `toData(): MagicLinkToken` maps to the DTO.

### Driver `cache`

- Key `filament-magic-login:{panelId}:{hash}` → serialized DTO, TTL = `expires_at - now`.
- `consume()` uses `Cache::lock("…:{hash}", 5)->get(fn () => Cache::pull($key))` so two simultaneous hits can't both win.
- `invalidateFor()` reads index key `filament-magic-login:index:{panelId}:{type}:{id}` (array of hashes), forgets each, clears index. Index TTL = max lifetime.
- Uses the store from config `storage.cache_store` (null = default store). Provider throws `LogicException` at boot if the resolved store is `array` or `file` and `app()->environment('production')`.
- No pruning needed; TTL handles it. No audit trail — say so in README.

### `Support\TokenGenerator`

```php
final readonly class TokenGenerator
{
    public function plaintext(): string   // Str::random(64)
    public function hash(string $plaintext): string  // hash('sha256', $plaintext)
}
```

Tokens are 64 chars from `Str::random` (≈380 bits). sha256 is adequate because tokens are high-entropy random values, not passwords — do **not** use bcrypt (makes lookup impossible without a separate selector).

---

## 4. Configuration

`config/filament-magic-login.php` — **global defaults**. Every value can be overridden per panel via the Plugin fluent API (§5).

```php
return [
    'storage' => [
        // 'database' (adds magic_login_tokens table, never touches users) or 'cache' (no migration).
        'driver' => 'database',
        'table' => 'magic_login_tokens',
        'cache_store' => null,
    ],

    // Link lifetime.
    'expires_after_minutes' => 15,

    // Where the action appears on the login page.
    // \Arzcode\FilamentMagicLogin\Enums\MagicLinkPosition::BelowForm | EmailFieldHint
    'position' => \Arzcode\FilamentMagicLogin\Enums\MagicLinkPosition::BelowForm,

    // Rate limiting of link *requests*: max attempts per decay window, keyed by email + IP.
    'rate_limit' => [
        'max_attempts' => 3,
        'decay_seconds' => 300,
    ],

    // Rate limiting of link *consumption* (brute force on the token URL).
    'consume_rate_limit' => [
        'max_attempts' => 10,
        'decay_seconds' => 60,
    ],

    // Invalidate the user's other unused tokens for the same panel when a new one is issued.
    'invalidate_previous' => true,

    // Notification class. Must accept (string $url, int $expiresAfterMinutes, string $panelId) in constructor.
    'notification' => \Arzcode\FilamentMagicLogin\Notifications\MagicLinkNotification::class,

    // Queue the notification (ShouldQueue). Set false to send synchronously.
    'queue' => true,

    // Route segment appended to the panel path: /{panel}/magic-login/{token}
    'route_path' => 'magic-login',

    // Forward the "remember me" checkbox state into the token so the magic-link session is also remembered.
    'honor_remember' => true,
];
```

---

## 5. Plugin API (`MagicLoginPlugin`)

Implements `Filament\Contracts\Plugin`. `getId()` returns `'magic-login'`.

```php
use Arzcode\FilamentMagicLogin\MagicLoginPlugin;
use Arzcode\FilamentMagicLogin\Enums\MagicLinkPosition;

$panel
    ->login()                                   // user keeps Filament's login() call
    ->plugin(
        MagicLoginPlugin::make()
            ->expiresAfter(minutes: 10)
            ->position(MagicLinkPosition::EmailFieldHint)
            ->label('Send me a login link')      // string|Closure|null, default from lang
            ->notification(MyMagicLinkMail::class)
            ->rateLimit(maxAttempts: 5, decaySeconds: 600)
            ->redirectTo(fn () => route('filament.admin.pages.dashboard')) // string|Closure|null; default: panel URL
            ->routePath('enlace')
            ->invalidatePrevious(false)
            ->honorRemember(false)
            ->useCustomLoginPage()               // see §6.2 — don't register our Login page, user adds the trait
    );
```

Every setter has a matching getter `getX()` that falls back to config. Storage driver is **global only** (config), not per panel — one repository binding for the app. Use `Filament\Support\Concerns\EvaluatesClosures` for closure-able options.

### `register(Panel $panel)`
1. **Login page wiring — zero files required in the host app by default.**
   - Read the panel's current login page class (Filament 5: `$panel->getLoginRouteAction()`; verify the exact getter in the installed source — if it returns a Closure, fall back to reading the protected property via the documented API or a small reflection helper, and unit-test it).
   - Case A — panel uses Filament's default `Filament\Auth\Pages\Login` (the dev just called `->login()`): the plugin calls `$panel->login(\Arzcode\FilamentMagicLogin\Pages\Login::class)`. The dev creates nothing. This is the common case.
   - Case B — panel has a custom login page whose class uses `HasMagicLinkAction`: do nothing, it already works.
   - Case C — panel has a custom login page **without** the trait: throw `LogicException` with the message from `lang::exceptions.custom_login_without_trait` telling the dev to `use HasMagicLinkAction` on that class. Never silently swap out the dev's page.
   - `useCustomLoginPage()` on the plugin is therefore only an escape hatch that skips the detection entirely (e.g. the dev registers the login page later via a closure). Keep it, document it as rarely needed.
   - Ordering caveat: plugin `register()` may run before or after the dev's `->login(Custom::class)` depending on chain order. Perform the detection in `boot()` (runs after all panel config) rather than `register()`, and test both chain orders.
2. Register the consume route **inside the panel's route group** so it inherits the panel's domain, path prefix and `web` middleware:
   ```php
   $panel->routes(function (Panel $panel) {
       Route::get(
           $this->getRoutePath() . '/{token}',
           ConsumeMagicLinkController::class,
       )
           ->middleware(['guest:' . $panel->getAuthGuard(), 'throttle:filament-magic-login-consume'])
           ->name('magic-login.consume');
   });
   ```
   Resulting route name: `filament.{panelId}.magic-login.consume`. Confirm `$panel->routes()` closures receive the Panel in Filament 5 — if not, capture `$panel` from the outer scope.
3. Define the two named rate limiters in `RateLimiter::for(...)` (do this in the service provider's `packageBooted()`, keyed by panel-aware limits read at runtime from the plugin).

### `boot(Panel $panel)`
Performs the login page detection/registration described above (§5 step 1). Nothing else.

### Accessing the plugin at runtime
`MagicLoginPlugin::get()` → `filament('magic-login')`. Always resolve via the current panel (`Filament::getCurrentPanel()->getPlugin('magic-login')`) so per-panel config is respected in the controller and actions.

---

## 6. Login page integration

### 6.1 `Pages\Login extends \Filament\Auth\Pages\Login`
Verify the FQCN of the Filament 5 login page (`Filament\Auth\Pages\Login` in v4; confirm v5 hasn't moved it). Contains only:

```php
class Login extends BaseLogin
{
    use HasMagicLinkAction;
}
```

### 6.2 Trait `Concerns\HasMagicLinkAction`

Responsibilities:

```php
trait HasMagicLinkAction
{
    public function magicLinkAction(): Action
    {
        return Action::make('magicLink')
            ->label(fn () => $this->getMagicLoginPlugin()->getLabel())
            ->link()
            ->color('gray')
            ->icon('heroicon-o-envelope')
            ->action(function (): void {
                $email = $this->getMagicLinkEmail();   // reads form state without full validation
                if (blank($email)) {
                    $this->addError('data.email', __('filament-magic-login::messages.email_required'));
                    return;
                }

                app(SendMagicLink::class)->handle(
                    panel: Filament::getCurrentPanel(),
                    email: $email,
                    remember: (bool) ($this->form->getRawState()['remember'] ?? false),
                    request: request(),
                );

                Notification::make()
                    ->title(__('filament-magic-login::messages.sent_title'))
                    ->body(__('filament-magic-login::messages.sent_body'))
                    ->success()
                    ->send();
            });
    }

    protected function getMagicLinkEmail(): ?string
    {
        // Do NOT call $this->form->getState() — it validates the password field too.
        return trim((string) ($this->form->getRawState()['email'] ?? '')) ?: null;
    }

    protected function getMagicLoginPlugin(): MagicLoginPlugin { ... }
}
```

Placement is driven by `MagicLinkPosition`:

- **`BelowForm`** (default): override `getFormActions(): array` → `[...parent::getFormActions(), $this->magicLinkAction()]`. Renders as a link under the "Sign in" button. Confirm the parent method name in Filament 5 (`getFormActions()` / `getCachedFormActions()`); use whatever the base Login page exposes publicly.
- **`EmailFieldHint`**: override `getEmailFormComponent(): Component` → `parent::getEmailFormComponent()->hintAction($this->magicLinkAction()->label(null)->tooltip(...))`. Renders as an envelope icon on the email field.

Implement both overrides in the trait; branch on position inside them. Tests must cover both positions.

The trait must register the action with Livewire properly — in Filament 5 actions are discovered by the `xxxAction()` method naming convention; make sure the name `magicLink` doesn't collide with anything in the base page.

**Validation note:** Rate-limit failure inside `SendMagicLink` must **still** show the generic success notification (do not leak that the limit was hit per-email). Log it and fire `MagicLinkRejected` instead. Only IP-level abuse should surface a visible "too many attempts" message — mirror the copy Filament's own login uses via `Filament\Auth\Http\Responses`/`TooManyRequestsException` if reusable.

---

## 7. Domain actions

### 7.1 `Actions\SendMagicLink`

```php
final readonly class SendMagicLink
{
    public function __construct(private TokenGenerator $tokens) {}

    public function handle(Panel $panel, string $email, bool $remember, Request $request): void
```

Steps:
1. Resolve plugin for `$panel`.
2. Rate limit: key `filament-magic-login:request:' . sha1(Str::lower($email) . '|' . $request->ip())`. If `tooManyAttempts` → fire `MagicLinkRejected(reason: 'rate_limited')`, `return` silently.
3. `RateLimiter::hit(...)`.
4. Find user through the panel guard's **user provider**: `Auth::guard($panel->getAuthGuard())->getProvider()->retrieveByCredentials(['email' => $email])`. Do not assume `App\Models\User`. If null → `return` silently (timing-safe enough; optionally `usleep` a random 50–150 ms to blur timing — **OPEN**, default yes).
5. If user implements `FilamentUser` and `! $user->canAccessPanel($panel)` → fire `MagicLinkRejected(reason: 'cannot_access_panel')`, `return` silently.
6. If `invalidatePrevious` → delete unused tokens for this user + panel.
7. Generate plaintext, store hash with `expires_at = now()->addMinutes($plugin->getExpiresAfterMinutes())`, `remember = $remember && $plugin->shouldHonorRemember()`, ip, UA, panel id, guard.
8. Build URL: `route("filament.{$panel->getId()}.magic-login.consume", ['token' => $plaintext])`. Absolute URL.
9. `$user->notify(new ($plugin->getNotificationClass())($url, $minutes, $panel->getId()))`. The notification's `via()` returns `['mail']`. If `queue` config is true the shipped notification implements `ShouldQueue`; custom notifications are the dev's responsibility.
10. Fire `MagicLinkRequested($user, $token, $panel->getId())`.

### 7.2 `Actions\ConsumeMagicLink`

```php
public function handle(Panel $panel, string $plaintext, Request $request): Authenticatable
```

Steps (throw `InvalidMagicLinkException` with a reason enum/const on any failure):
1. `$hash = $tokens->hash($plaintext)`; find token by hash **and** `panel_id = $panel->getId()`. Not found → reject `invalid`.
2. Expired → reject `expired`. Used → reject `used`.
3. Load `authenticatable`; null → reject `invalid`.
4. Guard mismatch (`$token->guard !== $panel->getAuthGuard()`) → reject `invalid`.
5. `FilamentUser` + `! canAccessPanel` → reject `cannot_access_panel`.
6. Atomically mark used: `MagicLoginToken::whereKey($token)->whereNull('used_at')->update(['used_at' => now()])` and check affected rows === 1 (protects against double-click / prefetch races). 0 rows → reject `used`.
7. `Filament::auth()->login($user, $token->remember)`; `$request->session()->regenerate()`.
8. Fire `MagicLinkConsumed($user, $token, $panel->getId())`.
9. Return user.

### 7.3 `Http\Controllers\ConsumeMagicLinkController` (invokable)

```php
public function __invoke(Request $request, string $token): RedirectResponse
{
    $panel = Filament::getCurrentPanel();
    try {
        app(ConsumeMagicLink::class)->handle($panel, $token, $request);
    } catch (InvalidMagicLinkException $e) {
        Notification::make()
            ->title(__('filament-magic-login::messages.invalid_title'))
            ->body(__("filament-magic-login::messages.invalid_reason.{$e->reason}"))
            ->danger()
            ->send();                          // session-flashed, rendered by Filament on next page
        return redirect()->to($panel->getLoginUrl());
    }

    return redirect()->intended($plugin->getRedirectUrl() ?? $panel->getUrl());
}
```

Confirm `Notification::make()->send()` outside Livewire flashes to session in Filament 5 (it does in v3/v4 via `Notification::send()` with `session()`). If not, use `->persistent()`/`session()->flash()` equivalent documented by Filament.

**Email client link prefetching** (Outlook SafeLinks, Apple Mail Privacy) can consume single-use links before the human clicks. **DECIDED for v1:** accept the risk but (a) treat `HEAD` requests as no-ops (route is `GET` only; add an explicit `Route::match(['HEAD'])` returning 204 without consuming if Laravel routes HEAD to GET — it does, so handle `$request->isMethod('HEAD')` at the top of the controller and return 204 early), and (b) document a `->confirmBeforeLogin()` option as a planned v1.1 feature (GET shows a "Continue" button, POST consumes). Do not build (b) now.

---

## 8. Notification `MagicLinkNotification`

```php
class MagicLinkNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $url,
        public readonly int $expiresAfterMinutes,
        public readonly string $panelId,
    ) {}

    public function via(object $notifiable): array { return ['mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('filament-magic-login::mail.subject', ['app' => config('app.name')]))
            ->greeting(__('filament-magic-login::mail.greeting'))
            ->line(__('filament-magic-login::mail.intro', ['minutes' => $this->expiresAfterMinutes]))
            ->action(__('filament-magic-login::mail.button'), $this->url)
            ->line(__('filament-magic-login::mail.ignore'));
    }
}
```

If config `queue` is false the provider must bind a non-queued variant — simplest: ship two classes (`MagicLinkNotification` and `QueuedMagicLinkNotification extends MagicLinkNotification implements ShouldQueue`) and pick in `SendMagicLink` based on config. **DECIDED:** two classes.

---

## 9. Events

All `final readonly` with public promoted properties, `Dispatchable`:

- `MagicLinkRequested(Authenticatable $user, MagicLoginToken $token, string $panelId)`
- `MagicLinkConsumed(Authenticatable $user, MagicLoginToken $token, string $panelId)`
- `MagicLinkRejected(string $reason, ?string $email, string $panelId, string $ip)` — reasons: `rate_limited`, `unknown_user`, `cannot_access_panel`, `invalid`, `expired`, `used`

Document listening to these for audit logging (Dictapp-style nginx/security log mindset).

---

## 10. Service provider

Via `spatie/laravel-package-tools`:

```php
$package
    ->name('filament-magic-login')
    ->hasConfigFile()
    ->hasMigration('create_magic_login_tokens_table')
    ->hasTranslations()
    ->hasInstallCommand(fn (InstallCommand $c) => $c
        ->publishConfigFile()
        ->publishMigrations()
        ->askToRunMigrations()
        ->askToStarRepoOnGitHub('arzcode/filament-magic-login'));
```

`packageBooted()`:
- Register rate limiters `filament-magic-login-request` and `filament-magic-login-consume` (the latter used by the route middleware, keyed by IP; the former is used manually in `SendMagicLink`).
- Bind `TokenGenerator` as singleton.
- Bind `TokenRepository` to `DatabaseTokenRepository` or `CacheTokenRepository` from `storage.driver`; fail fast on unknown driver.
- Install command: skip `publishMigrations`/`askToRunMigrations` prompts if `storage.driver === 'cache'`.

No Filament asset registration needed (no JS/CSS). No Blade views unless a position variant needs one.

---

## 11. Translations

Three files with identical keys: `resources/lang/{en,es,ca}/filament-magic-login.php`. Spanish is **Spain Spanish**, Catalan is **central Catalan** (IEC standard). Use informal second person in all three (`you` / `tú` / `tu`), matching Filament's own default translations. All strings below are the **only** user-facing copy in the package; any new string must be added to all three files.

```php
// en
return [
    'actions' => [
        'magic_link' => 'Email me a login link',
        'magic_link_tooltip' => 'Sign in without a password',
    ],
    'messages' => [
        'email_required' => 'Enter your email address first.',
        'sent_title' => 'Check your inbox',
        'sent_body' => 'If an account exists for that address, we\'ve sent a login link. It expires in :minutes minutes.',
        'too_many_requests_title' => 'Too many attempts',
        'too_many_requests_body' => 'Please wait :seconds seconds before trying again.',
        'invalid_title' => 'That login link can\'t be used',
        'invalid_reason' => [
            'invalid' => 'The link is not valid.',
            'expired' => 'The link has expired. Request a new one.',
            'used' => 'The link has already been used. Request a new one.',
            'cannot_access_panel' => 'You don\'t have access to this panel.',
        ],
    ],
    'mail' => [
        'subject' => 'Your login link for :app',
        'greeting' => 'Hello!',
        'intro' => 'Click the button below to sign in. The link expires in :minutes minutes and can only be used once.',
        'button' => 'Sign in',
        'ignore' => 'If you didn\'t request this, you can safely ignore this email.',
        'fallback' => 'If the button doesn\'t work, copy this URL into your browser:',
    ],
    'exceptions' => [
        'custom_login_without_trait' => 'Panel [:panel] uses a custom login page [:class] that does not use :trait. Add the trait or call ->useCustomLoginPage().',
        'unknown_storage_driver' => 'Unknown filament-magic-login storage driver [:driver]. Use "database" or "cache".',
        'unsafe_cache_store' => 'The [:store] cache store cannot be used for filament-magic-login in production.',
    ],
    'install' => [
        'cache_driver_skip_migrations' => 'Storage driver is "cache": no migration needed.',
    ],
];
```

```php
// es (España)
return [
    'actions' => [
        'magic_link' => 'Envíame un enlace de acceso',
        'magic_link_tooltip' => 'Entrar sin contraseña',
    ],
    'messages' => [
        'email_required' => 'Introduce primero tu dirección de correo electrónico.',
        'sent_title' => 'Revisa tu bandeja de entrada',
        'sent_body' => 'Si existe una cuenta con esa dirección, te hemos enviado un enlace de acceso. Caduca en :minutes minutos.',
        'too_many_requests_title' => 'Demasiados intentos',
        'too_many_requests_body' => 'Espera :seconds segundos antes de volver a intentarlo.',
        'invalid_title' => 'Este enlace de acceso no se puede usar',
        'invalid_reason' => [
            'invalid' => 'El enlace no es válido.',
            'expired' => 'El enlace ha caducado. Solicita uno nuevo.',
            'used' => 'El enlace ya se ha utilizado. Solicita uno nuevo.',
            'cannot_access_panel' => 'No tienes acceso a este panel.',
        ],
    ],
    'mail' => [
        'subject' => 'Tu enlace de acceso a :app',
        'greeting' => '¡Hola!',
        'intro' => 'Pulsa el botón de abajo para entrar. El enlace caduca en :minutes minutos y solo se puede usar una vez.',
        'button' => 'Entrar',
        'ignore' => 'Si no has solicitado este enlace, puedes ignorar este correo.',
        'fallback' => 'Si el botón no funciona, copia esta URL en tu navegador:',
    ],
    'exceptions' => [
        'custom_login_without_trait' => 'El panel [:panel] usa una página de acceso personalizada [:class] que no utiliza :trait. Añade el trait o llama a ->useCustomLoginPage().',
        'unknown_storage_driver' => 'Driver de almacenamiento desconocido para filament-magic-login [:driver]. Usa "database" o "cache".',
        'unsafe_cache_store' => 'El store de caché [:store] no puede usarse con filament-magic-login en producción.',
    ],
    'install' => [
        'cache_driver_skip_migrations' => 'El driver de almacenamiento es "cache": no hace falta migración.',
    ],
];
```

```php
// ca
return [
    'actions' => [
        'magic_link' => 'Envia\'m un enllaç d\'accés',
        'magic_link_tooltip' => 'Entrar sense contrasenya',
    ],
    'messages' => [
        'email_required' => 'Introdueix primer la teva adreça de correu electrònic.',
        'sent_title' => 'Revisa la teva safata d\'entrada',
        'sent_body' => 'Si existeix un compte amb aquesta adreça, t\'hem enviat un enllaç d\'accés. Caduca d\'aquí a :minutes minuts.',
        'too_many_requests_title' => 'Massa intents',
        'too_many_requests_body' => 'Espera :seconds segons abans de tornar-ho a provar.',
        'invalid_title' => 'Aquest enllaç d\'accés no es pot fer servir',
        'invalid_reason' => [
            'invalid' => 'L\'enllaç no és vàlid.',
            'expired' => 'L\'enllaç ha caducat. Sol·licita\'n un de nou.',
            'used' => 'L\'enllaç ja s\'ha fet servir. Sol·licita\'n un de nou.',
            'cannot_access_panel' => 'No tens accés a aquest tauler.',
        ],
    ],
    'mail' => [
        'subject' => 'El teu enllaç d\'accés a :app',
        'greeting' => 'Hola!',
        'intro' => 'Prem el botó de sota per entrar. L\'enllaç caduca d\'aquí a :minutes minuts i només es pot fer servir una vegada.',
        'button' => 'Entrar',
        'ignore' => 'Si no has sol·licitat aquest enllaç, pots ignorar aquest correu.',
        'fallback' => 'Si el botó no funciona, copia aquesta URL al teu navegador:',
    ],
    'exceptions' => [
        'custom_login_without_trait' => 'El tauler [:panel] fa servir una pàgina d\'accés personalitzada [:class] que no utilitza :trait. Afegeix el trait o crida ->useCustomLoginPage().',
        'unknown_storage_driver' => 'Driver d\'emmagatzematge desconegut per a filament-magic-login [:driver]. Fes servir "database" o "cache".',
        'unsafe_cache_store' => 'L\'store de memòria cau [:store] no es pot fer servir amb filament-magic-login en producció.',
    ],
    'install' => [
        'cache_driver_skip_migrations' => 'El driver d\'emmagatzematge és "cache": no cal cap migració.',
    ],
];
```

Rules:
- Notification `toMail()` must use `Lang::get`/`__()` under the notifiable's locale: wrap in `$notifiable->preferredLocale()` via `HasLocalePreference` if implemented, else app locale. Add the `fallback` line with the raw URL (mail clients that strip buttons).
- Exceptions thrown to developers (LogicException) also use lang keys — they surface in logs/Ignition and a Spanish team will thank you.
- Test: `it('ships identical translation keys in every locale')` — flatten each file with `Arr::dot()` and assert equal key sets. Test: `it('has no untranslated literal strings')` — grep `src/` for `'…'` passed to `->label(`, `->title(`, `->body(`, `->subject(`, `->line(`, `->action(` that are not `__()`/`trans()` calls. Keep this simple (regex), it's a guard not a parser.

## 12. Testing (Pest 5)

### Setup
- `tests/TestCase.php` extends Orchestra `TestCase`; loads `FilamentServiceProvider`, Livewire, our provider, and a fixture `AdminPanelProvider` registering a panel `admin` with `->login()` and `MagicLoginPlugin::make()`, plus a second panel `app` for multi-panel tests.
- Fixture `User` model implements `FilamentUser` with `canAccessPanel()` returning `$this->can_access` attribute (default true).
- SQLite in-memory; run package migration + a users table migration.
- `Notification::fake()` and `Event::fake()` where appropriate.

### Feature tests (minimum list — each is its own `it()`)

**Login page**
1. Login page renders the `magicLink` action in `BelowForm` position.
2. Login page renders the hint action in `EmailFieldHint` position.
3. Calling the action with empty email adds a validation error on `data.email` and sends nothing.
4. Calling the action with an unknown email shows the success notification and sends nothing.
5. Calling the action with a known email sends `MagicLinkNotification` to that user, creates one token row with correct `panel_id`, `guard`, `expires_at ≈ now + minutes`.
6. `remember` checkbox state is persisted on the token when `honorRemember` is true, ignored when false.
7. Requesting twice with `invalidatePrevious` true leaves exactly one unused token; with false leaves two.
8. Exceeding `rate_limit.max_attempts` sends nothing further, still shows success notification, fires `MagicLinkRejected(rate_limited)`.
9. User with `can_access = false` receives nothing, fires `MagicLinkRejected(cannot_access_panel)`.
10. Custom notification class from plugin config is the one dispatched.
11. Password login still works on the page (regression).

**Consume route**
12. Valid token → user authenticated on panel guard, redirected to panel URL, token `used_at` set, `MagicLinkConsumed` fired, session id regenerated.
13. Valid token with `remember = true` → remember cookie present.
14. Expired token → redirect to login, not authenticated, danger notification flashed with `expired` reason.
15. Used token → same with `used` reason.
16. Garbage token → `invalid`.
17. Token issued for panel `app` used on panel `admin` → `invalid`.
18. Token for a user whose `can_access` flipped to false → `cannot_access_panel`.
19. Already-authenticated visitor hitting the route is redirected by `guest` middleware (not consumed — token remains unused).
20. `HEAD` request returns 204 and does not consume the token.
21. Consume route is throttled: N+1 garbage requests from one IP → 429.
22. Custom `redirectTo` closure is honoured.
23. Custom `routePath` changes the URL and the notification link.

**Storage drivers**
Run the entire Feature suite twice via a dataset `['database', 'cache']` that sets `storage.driver` in `TestCase::defineEnvironment()` (cache tests use the `array` store, which is allowed outside production). Additionally:
- Cache driver: two concurrent `consume()` calls on the same token → exactly one `true`.
- Cache driver: booting in `production` with `array` store throws.

**Model / Unit**
24. `Prunable` removes tokens expired > 24 h ago and keeps newer ones.
25. `TokenGenerator` produces 64-char tokens and stable sha256 hashes.
26. Plugin getters fall back to config values when setters aren't called; setters override.
27. Registering the plugin on a panel that already has a custom login page without `useCustomLoginPage()` throws `LogicException` (skip if no clean Filament API — document).

Use `Livewire::test(Login::class)->fillForm([...])->callAction('magicLink')` for page tests, `$this->get($url)` for route tests, `Notification::assertSentTo`, `Event::assertDispatched`.

### Tooling
- `composer test` → `vendor/bin/pest`
- `composer analyse` → `vendor/bin/phpstan analyse`
- `composer format` → `vendor/bin/pint`
- GitHub Actions matrix: PHP 8.3/8.4 × Laravel 12/13, `prefer-lowest` + `prefer-stable`.

---

## 13. README outline

1. What it does (one paragraph + screenshot placeholder for both positions).
2. Install: `composer require arzcode/filament-magic-login` → `php artisan filament-magic-login:install`.
3. Register plugin (minimal example).
4. Configuration table (every fluent method + config key + default).
5. Using with a custom Login page (`useCustomLoginPage()` + trait).
6. Customising the email (own Notification class; constructor contract).
7. Events for audit logging.
8. Storage: database vs cache, with the explicit note that the `users` table is never modified and `remember_token` is deliberately not reused (and why).
9. Pruning (database driver): add `MagicLoginToken::class` to `model:prune` schedule (`Schedule::command('model:prune')->daily()`).
10. Security notes: hashed tokens, single-use, panel binding, rate limits, enumeration-safe responses, email-scanner prefetch caveat and the planned `confirmBeforeLogin()`.
11. Testing / contributing / license.

---

## 14. Implementation order (do in this sequence, commit after each)

1. Scaffold with package-tools skeleton (composer.json, provider, config, migration stub, Testbench + Pest wiring, Pint, Larastan). Get `composer test` green with one smoke test.
2. `TokenGenerator`, `TokenRepository` contract, DTO, `DatabaseTokenRepository` + model + Prunable, `CacheTokenRepository`, unit tests for both.
3. `MagicLoginPlugin` with all fluent options + getter fallback tests.
4. Consume route registration + `ConsumeMagicLink` + controller + tests 12–23.
5. `SendMagicLink` + notifications + events + tests 4–10.
6. `HasMagicLinkAction` trait + `Pages\Login` + both positions + tests 1–3, 11.
7. Translations en/es/ca + key-parity and no-literal-string tests.
8. README, CHANGELOG, LICENSE, GitHub Actions.
9. Final pass: Pint, Larastan level 6, `composer validate`, tag `v0.1.0`.

Before step 4, open the installed `filament/filament` source (`vendor/filament/filament/src/Auth/Pages/Login.php` or wherever v5 keeps it) and confirm the exact method names for: the email form component getter, the form actions getter, the authenticate action, `getLoginUrl()` on Panel, `Panel::routes()`, and `Panel::getAuthGuard()`. Adjust the trait to the real API rather than guessing.

---

## 15. Deliverable checklist

- [ ] `composer test` green, all 27 scenarios covered
- [ ] `composer analyse` clean at level 6
- [ ] `composer format --test` clean
- [ ] Works on a fresh Laravel 13 + Filament 5 app with default `admin` panel using only the README steps
- [ ] Works on a second panel with different `routePath` and `expiresAfter` simultaneously
- [ ] No Filament views overridden; no JS/CSS assets
- [ ] Plaintext token never written to DB, cache, or logs
- [ ] `en`/`es`/`ca` lang files with identical key sets; no literal user-facing strings in `src/`
- [ ] `LICENSE.md` (MIT) present; `composer.json` `license: MIT`
- [ ] Host app `users` table untouched; full suite green under both storage drivers
