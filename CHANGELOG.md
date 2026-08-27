# Changelog

All notable changes to `filament-magic-login` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.4.0 - 2026-08-27

### Added

- An administrator can now email a specific user a login link from inside a panel, choosing how
  long it lives. `SendMagicLinkAction::make()` serves a table row action and a header action on
  the View and Edit pages from one class — an icon button in the row, a labelled button in the
  page headers. The expiry is a row of toggle buttons — 15 minutes to
  three days by default — with a "custom" choice, clamped to a configurable ceiling. The link is
  only ever emailed to the target user and is never shown to the administrator, so this helps
  somebody log in rather than logging in as them.
- Unlike the login page, which stays silent so an anonymous visitor cannot probe which addresses
  exist, the administrator is told what happened: sent, no email address, cannot access this
  panel, or too many links sent. The person reading it is authenticated and can already see the
  users table, so none of those answers tells them anything new. Admin sends are rate limited by
  administrator, in their own key namespace, so they never eat into the recipient's own allowance
  for asking on the login page.
- The action only shows for a user a link could actually help: it hides itself on the
  administrator's own row — somebody already signed in has no use for a link to where they already
  are — and on any user the target panel would turn away, which is the answer `canAccessPanel()`
  gives while the row is being drawn. A user who cannot reach the panel you are looking at but can
  reach another one gets the action wherever you name that panel: `->panel('app')` decides both
  which panel the link is minted for and which panel's door is asked about, so two placements can
  sit side by side, each showing only for the users its own panel would admit. `SendMagicLinkToUser`
  still refuses the send outright, which covers access revoked after the page was drawn.
- Where a users table mixes people who belong to different panels, `->panels(['admin', 'app'])`
  hands the action an ordered list and mints the link for the first panel that row's user can
  actually reach — a colleague gets an admin link, a client gets an app one, and somebody in both
  is sent where the administrator is standing. `->anyPanel()` does the same over every panel that
  registers the plugin, current panel first. The confirmation modal names the panel whenever it is
  not the one being looked at, so "send a login link" never quietly means a different door.
- The envelope icon is now configurable rather than hard-coded: `icon()` on the plugin for the
  login page's action, `adminIcon()` for the "send a login link" action and its modal — which
  follows `icon()` unless you set it — and Filament's own `->icon()` on the action for one
  placement. `false` removes the icon altogether, a `BackedEnum` works as well as a string, and a
  name no icon set has falls back down the same chain rather than rendering — a typo like
  `heroicon-s-email` costs the icon, where it would otherwise be an `SvgNotFound` error page on
  the whole users table. That check runs on whatever the icon turns out to be, including a name
  handed to Filament's own `->icon()` on the action. Both have config keys, `icon` and
  `admin.icon`.
- Per-panel and config options for all of it: `adminExpiresAfter()`, `expiryPresets()`,
  `maxAdminExpiresAfter()`, `adminRateLimit()` and `adminAbility()`, under a new `admin` block in
  the config file.
- The install command offers to add the action to your user resource — its table and the header of
  each record page — found by comparing each resource's `$model` against the model your panel's
  guard authenticates. Every question defaults to no, and anything it cannot rewrite with
  certainty is reported rather than guessed at, the same as every other step. The uninstall
  command strips the action back out again.

### Changed

- Every message naming a link's lifetime now says it the way a person would: an hour or less
  stays in minutes, anything longer becomes hours and days, so a three-day link reads as
  "expires in 3 days" rather than "expires in 4320 minutes". The three strings that carry it —
  `messages.sent_body`, `mail.intro` and `admin.sent.body` — take a `:duration` placeholder in
  place of `:minutes`, which is a breaking change for a published translation of them.
- `MagicLinkRequested` gained an optional fourth argument, `$issuedBy`, naming the administrator
  who sent the link, along with `wasIssuedByAdministrator()`. Backward compatible: no migration,
  no config change, and existing listeners are untouched. It was added to the existing event
  rather than shipped as a new one so that nobody's audit log quietly stops seeing half of all
  issuances.

### Internal

- `SendMagicLink` now delegates minting, storing and emailing to a new `IssueMagicLink`, which the
  administrator's path shares. Keeping that tail in one place is what stops `invalidate_previous`,
  the queued-notification swap and the URL shape from drifting between the two entry points.

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
