# Changelog

All notable changes to `filament-magic-login` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0]

### Added

- "Email me a login link" action for Filament 5 panels, alongside the password form.
- Two placements: a full-width uncoloured button below the form (default), or a
  labelled hint action on the email field.
- Single-use, hashed, time-limited tokens bound to a panel and guard.
- `database` (default) and `cache` storage drivers behind a `TokenRepository` contract.
- Per-panel plugin configuration with global config fallbacks.
- `MagicLinkRequested`, `MagicLinkConsumed` and `MagicLinkRejected` events.
- Rate limiting of both link requests and link redemptions.
- `filament-magic-login:uninstall` command that removes the published files and the
  tokens table, with `--force` and `--keep-tokens`.
- English, Spanish and Catalan translations.
- Magic-link logins skip Filament's 2FA challenge (the emailed link is the second
  factor) while still honouring a panel's forced 2FA enrolment.
