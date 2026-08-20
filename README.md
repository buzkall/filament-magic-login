# Filament Magic Login

[![Latest Version on Packagist](https://img.shields.io/packagist/v/arzcode/filament-magic-login.svg?style=flat-square)](https://packagist.org/packages/arzcode/filament-magic-login)
[![Tests](https://img.shields.io/github/actions/workflow/status/buzkall/filament-magic-login/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/buzkall/filament-magic-login/actions/workflows/tests.yml)
[![License](https://img.shields.io/packagist/l/arzcode/filament-magic-login.svg?style=flat-square)](LICENSE)

Passwordless **"email me a login link"** for Filament panels, added *alongside* the standard
email/password form rather than replacing it. Multi-panel aware, single-use hashed tokens,
translated into English, Spanish and Catalan.

The action appears in one of two places, chosen per panel:

| `MagicLinkPosition::BelowForm` (default) | `MagicLinkPosition::EmailFieldHint` |
|---|---|
| <img src="art/below-form.png" alt="Filament login form with an 'Email me a login link' button under the Sign in button" width="420"> | <img src="art/email-field-hint.png" alt="Filament login form with an 'Email me a login link' hint action on the email field" width="420"> |
| A full-width button under the "Sign in" button — same shape, no colour. | A labelled link on the email field's hint row. |

Nothing about the password form changes: password login, password reset and registration keep
working exactly as before.

---

## Requirements

| | |
|---|---|
| PHP | 8.3+ |
| Laravel | 12 or 13 |
| Filament | 5 |

## Installation

```bash
composer require arzcode/filament-magic-login
php artisan filament-magic-login:install
```

The install command publishes the config file and — unless you have chosen the `cache` storage
driver — publishes and offers to run the migration that creates the `magic_login_tokens` table.

**Your `users` table is never touched.** See [Storage](#storage) for why `remember_token` is
deliberately not reused.

### Uninstalling

```bash
php artisan filament-magic-login:uninstall
composer remove arzcode/filament-magic-login
```

The uninstall command undoes what the install command did, in the order that leaves the
application working at every step:

1. Removes `->plugin(MagicLoginPlugin::make())` from your panel providers and the
   `HasMagicLinkAction` trait from any custom login page, along with the now-unused imports.
   Without this, `composer remove` would leave your provider pointing at a class that no longer
   exists and every request would fail.
2. Drops the `magic_login_tokens` table.
3. Deletes the published config, migration and translations.

Both destructive steps are confirmed first.

| Option | Effect |
|---|---|
| `--force` | Skip every confirmation (for CI or scripted teardown). |
| `--keep-tokens` | Leave the table and its rows alone. |
| `--keep-code` | Do not touch your source; report the panels to unwire by hand instead. |

The code edits work on PHP's token stream, not regular expressions, and the result is parsed
before it is written — a file that would not compile is left untouched. Registrations it cannot
rewrite with certainty (a grouped `use A, B;`, a plugin built through a variable, a conditional
registration) are reported with their file and line numbers rather than guessed at, as are any
other references such as event listeners importing `MagicLinkConsumed`.

## Register the plugin

Add the plugin to any panel that already calls `->login()`:

```php
use Arzcode\FilamentMagicLogin\MagicLoginPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->id('admin')
        ->login()
        ->plugin(MagicLoginPlugin::make());
}
```

That is the whole setup. The plugin swaps Filament's stock login page for its own subclass, so
you do not have to create any files. If your panel uses a **custom** login page, see
[Using your own login page](#using-your-own-login-page).

## Configuration

Every option has a global default in `config/filament-magic-login.php` and a per-panel override
on the plugin. Panel-level setters win; anything you do not set falls back to config.

| Fluent method | Config key | Default | What it does |
|---|---|---|---|
| `expiresAfter(int\|Closure $minutes)` | `expires_after_minutes` | `15` | Link lifetime. |
| `position(MagicLinkPosition\|Closure)` | `position` | `BelowForm` | Where the action renders. |
| `label(string\|Closure\|null)` | — | from translations | Action label. |
| `notification(class-string\|Closure)` | `notification` | `MagicLinkNotification` | Notification used to deliver the link. |
| `rateLimit(int $maxAttempts, int $decaySeconds)` | `rate_limit.*` | `3` / `300` | Limits link *requests*, keyed by email + IP. |
| `consumeRateLimit(int $maxAttempts, int $decaySeconds)` | `consume_rate_limit.*` | `10` / `60` | Limits link *consumption*, keyed by IP. |
| `redirectTo(string\|Closure\|null)` | — | the panel URL | Where to land after a successful login. |
| `routePath(string\|Closure)` | `route_path` | `magic-login` | Route segment under the panel path. |
| `invalidatePrevious(bool\|Closure)` | `invalidate_previous` | `true` | Drop the user's other unused tokens for this panel when a new link is issued. |
| `honorRemember(bool\|Closure)` | `honor_remember` | `true` | Carry the "remember me" checkbox into the magic-link session. |
| `useCustomLoginPage(bool\|Closure)` | — | `false` | Skip login page detection entirely. |
| — | `queue` | `true` | Send the shipped notification on the queue. |
| — | `storage.driver` | `database` | `database` or `cache`. Global only, not per panel. |
| — | `blur_timing` | `true` | Pad unknown-address responses so timing does not leak account existence. |

A fuller example:

```php
MagicLoginPlugin::make()
    ->expiresAfter(minutes: 10)
    ->position(MagicLinkPosition::EmailFieldHint)
    ->label('Send me a login link')
    ->notification(MyMagicLinkNotification::class)
    ->rateLimit(maxAttempts: 5, decaySeconds: 600)
    ->redirectTo(fn () => route('filament.admin.pages.dashboard'))
    ->routePath('enlace')
    ->invalidatePrevious(false)
    ->honorRemember(false);
```

Two panels can run different settings at the same time — each panel gets its own plugin
instance and its own consume route (`filament.{panelId}.magic-login.consume`).

## Using your own login page

If the panel already has a custom login page, add the trait to it:

```php
use Arzcode\FilamentMagicLogin\Concerns\HasMagicLinkAction;
use Filament\Auth\Pages\Login;

class MyLogin extends Login
{
    use HasMagicLinkAction;
}
```

The plugin then leaves your page alone. If it finds a custom login page **without** the trait it
throws a `LogicException` at boot rather than silently replacing your page — either add the trait,
or call `->useCustomLoginPage()` to opt out of the check entirely.

Registration order does not matter: `->login(MyLogin::class)->plugin(...)` and
`->plugin(...)->login(MyLogin::class)` behave identically.

## Customising the email

Any notification class works as long as it satisfies the constructor contract:

```php
use Arzcode\FilamentMagicLogin\Contracts\MagicLinkNotification;
use Illuminate\Notifications\Notification;

class MyMagicLinkNotification extends Notification implements MagicLinkNotification
{
    public function __construct(
        public string $url,
        public int $expiresAfterMinutes,
        public string $panelId,
    ) {}

    // ...
}
```

The simplest route is to extend `Arzcode\FilamentMagicLogin\Notifications\MagicLinkNotification`,
which already implements the contract and gives you a translated `MailMessage` to tweak.

Queueing a custom notification is your call — implement `ShouldQueue` on it. The `queue` config
option only decides whether the *shipped* notification is queued.

Notifications are sent through Laravel's notification system, so a notifiable implementing
`HasLocalePreference` receives the email in its own locale automatically.

## Events

Listen to these for audit logging:

| Event | Fired when |
|---|---|
| `MagicLinkRequested($user, $token, $panelId)` | A link was issued and emailed. |
| `MagicLinkConsumed($user, $token, $panelId)` | A link was redeemed and the user signed in. |
| `MagicLinkRejected($reason, $email, $panelId, $ip)` | Anything was refused. |

`MagicLinkRejected` reasons: `rate_limited`, `unknown_user`, `cannot_access_panel`, `invalid`,
`expired`, `used`. The first three are raised on the request side (where the UI deliberately
stays silent), the rest on the consume side.

```php
Event::listen(MagicLinkRejected::class, function (MagicLinkRejected $event): void {
    Log::channel('security')->warning('magic link rejected', [
        'reason' => $event->reason,
        'email' => $event->email,
        'panel' => $event->panelId,
        'ip' => $event->ip,
    ]);
});
```

## Storage

### `database` (default)

Adds a `magic_login_tokens` table. Each row stores the token **hash**, the panel, the guard, the
remember flag, requesting IP and user agent, an expiry and a `used_at` timestamp — so you can
audit exactly which links were issued and redeemed.

### `cache`

No migration at all. Entries are keyed by hash and expire on their own.

- Use a shared store — **Redis, Memcached or the database cache**. `array` and `file` are refused
  in production, because a multi-process deployment cannot share them.
- Consumption is guarded by `Cache::lock()`, so two simultaneous clicks cannot both win.
- Consumed tokens are kept (marked used) for 24 hours so "already used" and "expired" stay
  distinguishable from "never existed" — after that the distinction is lost.
- No audit trail: listen to the events above if you need one.

Set it globally in config; it is not a per-panel option.

```php
'storage' => ['driver' => 'cache'],
```

### Why not reuse `users.remember_token`?

Deliberately not, for four reasons:

1. It is a **long-lived plaintext** credential that Laravel's remember-me cookie already depends
   on. Emailing it turns the email into a permanent session key.
2. It has **no expiry, no `used_at`, and no panel or guard binding**.
3. Making it single-use means rotating it, which silently signs the user out of every remembered
   device.
4. One value per user means no concurrent links and nothing to audit.

Laravel's own `password_reset_tokens` is the precedent this package follows: a separate,
hashed, expiring table — with extra columns for panel binding and single-use semantics.

## Pruning (database driver)

Rows are kept for 24 hours after they expire, then become prunable. Schedule Laravel's pruner:

```php
// bootstrap/app.php or a service provider
Schedule::command('model:prune', [
    '--model' => [\Arzcode\FilamentMagicLogin\Models\MagicLoginToken::class],
])->daily();
```

The cache driver needs no pruning.

## Security notes

- **Hashed at rest.** Only a SHA-256 hash of the token is stored; the plaintext exists solely in
  the emailed URL. A fast hash is correct here — tokens are 64 random characters (~380 bits), not
  passwords — and it keeps lookup by hash possible without a separate selector column.
- **Single-use.** Consumption is a conditional update (`where used_at is null`) on the database
  driver and a lock-guarded write on the cache driver, so a double-click or a prefetch race
  redeems the link exactly once.
- **Panel and guard bound.** A token minted for one panel is rejected on every other panel, and a
  guard mismatch is rejected outright.
- **`canAccessPanel()` is honoured** both when issuing and when redeeming, so access revoked after
  a link was sent still blocks the login.
- **No user enumeration.** Unknown addresses, users who cannot access the panel and rate-limited
  requests all produce the same confirmation message, and unknown addresses are padded in time.
- **Rate limited on both sides**: requests by email + IP, redemptions by IP.
- **Existing users only.** A magic link never creates an account.

### Multi-factor authentication

**A magic link does not trigger the 2FA challenge, by design.** Receiving and clicking a link sent
to the account's mailbox already proves possession of a second factor, so asking for a TOTP code on
top would be a second challenge for the same guarantee.

Concretely: Filament 5 runs its multi-factor challenge inside the login page's `authenticate()`
method rather than in middleware, and this package authenticates through the panel guard directly.
Password logins on the same panel keep their challenge, untouched.

Bypassing the *challenge* does not bypass the *enrolment* requirement. A panel configured with
`->multiFactorAuthentication([...], isRequired: true)` still sends a magic-link user to the set-up
page until they have registered a second factor, exactly as it would after a password login. Both
behaviours are covered by tests.

If your threat model wants a TOTP code even after a mailbox-proven login, don't register this
plugin on that panel — there is no option to re-add the challenge.

### Email scanners

Outlook SafeLinks, Apple Mail Privacy Protection and similar scanners follow links before a human
does. `HEAD` requests are answered with `204 No Content` without consuming the token, which covers
the common case. A scanner issuing a real `GET` will still burn the link; a `confirmBeforeLogin()`
option (GET shows a "Continue" button, POST consumes) is planned for a later release.

## Testing

```bash
composer test      # the whole suite against both storage drivers
composer analyse   # Larastan, level 6
composer format    # Pint
```

## Trying it in one of your own projects

No need to publish anything. Point the project's `composer.json` at your local checkout with a
[path repository](https://getcomposer.org/doc/05-repositories.md#path):

```jsonc
"repositories": [
    {
        "type": "path",
        "url": "../filament-magic-login",       // wherever you cloned it
        "options": { "symlink": true }          // true = edits apply instantly
    }
],
```

Then require it as a dev version and install as usual:

```bash
composer require arzcode/filament-magic-login:@dev
php artisan filament-magic-login:install
```

With `"symlink": true` the package is symlinked, so changes in your checkout take effect
immediately — no `composer update` between edits. Use `"symlink": false` to copy instead, which
mimics a real install more closely (run `composer update arzcode/filament-magic-login` after
each change).

Remember to drop the `repositories` entry before deploying that project.

## Contributing

Pull requests are welcome. Please keep `composer test`, `composer analyse` and
`composer format --test` green, and add any new user-facing string to all three language files —
a test asserts they carry identical keys.

## License

MIT. See [LICENSE](LICENSE).
