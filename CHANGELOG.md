# Changelog

All notable changes to `filament-magic-login` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
