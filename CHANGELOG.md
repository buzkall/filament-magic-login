# Changelog

All notable changes to `filament-magic-login` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.3.0 - 2026-08-25

### Fixed

- A password manager filling the login form could send a magic link on its own. Managers that
  sign you in after filling pick a button out of the login form and click it — ignoring
  `type="submit"` — and "email me a login link" reads to them like a second way in, so the link
  went out before anyone touched the button. 1Password documents that it clicks with
  `element.click()`, which produces an event carrying `isTrusted: false`, so the action is now
  mounted from an Alpine handler behind that check instead of from `wire:click`: a click no hand
  made reaches nothing, in both positions. A real click, and Enter or Space on the focused button,
  are trusted and unaffected.
- Two further layers behind that one: the below-form button now renders *outside* the `<form>`
  element (form and button share a wrapper, so nothing moves on screen), and the button carries
  the opt-out attributes the major extensions read — `data-1p-ignore`, `data-lpignore`,
  `data-bwignore` and `data-form-type`.

## 1.2.0 - 2026-08-24

### Added

- The install command offers to register the plugin on a panel provider for you, appending
  `->plugin(MagicLoginPlugin::make())` to the chain the provider returns and adding the import.
  Without this step the rest of the install changes nothing you can see: the table exists, the
  pruner runs, and no login page ever grows the action. It stays quiet when the plugin is already
  registered, defaults to *no* so an unattended install never rewrites a provider, and reports any
  provider it cannot rewrite with certainty instead of guessing at it.

### Changed

- Publishing the config file is now a question like every other install step, instead of the one
  thing the command did to you unasked. Decline it and the package defaults apply, since the
  service provider merges them either way.

## 1.1.0 - 2026-08-24

### Added

- The install command offers to schedule the token pruner in `routes/console.php` for you, on the
  database driver only. It skips the question when the pruner is already scheduled, defaults to
  *no* so an unattended install never rewrites your console routes, and prints the snippet instead
  of guessing whenever the file cannot be edited with certainty.

### Changed

- The install command is now hand-rolled on Laravel Prompts instead of spatie's, so the whole run
  reads in one voice. It also asks before overwriting a config file you have already published,
  and says what it did at each step. This adds `laravel/prompts` to the package's requirements;
  Laravel 12 and 13 already ship it.

## 1.0.0 - 2026-08-24

### Added

- "Email me a login link" action for Filament 5 panels, alongside the password form.
- Two placements: a full-width uncoloured button below the form (default), set
  apart from "Sign in" by an "or" rule, or a labelled hint action on the email
  field.
- Single-use, hashed, time-limited tokens bound to a panel and guard.
- `database` (default) and `cache` storage drivers behind a `TokenRepository` contract.
- Per-panel plugin configuration with global config fallbacks.
- `MagicLinkRequested`, `MagicLinkConsumed` and `MagicLinkRejected` events.
- Rate limiting of both link requests and link redemptions.
- `filament-magic-login:uninstall` command that unwires the plugin from panel
  providers and login pages, drops the tokens table and deletes the published
  files, with `--force`, `--keep-tokens` and `--keep-code`.
- English, Spanish and Catalan translations.
- Magic-link logins skip Filament's 2FA challenge (the emailed link is the second
  factor) while still honouring a panel's forced 2FA enrolment.
