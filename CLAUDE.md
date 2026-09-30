# filament-magic-login

A Filament plugin package (no app), tested with Orchestra Testbench.

## Before finishing a change

Run these in order; CI enforces all three:

1. `composer format` — Pint, `laravel` preset
2. `composer analyse` — Larastan at level 10
3. `composer test` — runs the suite twice, with `MAGIC_LOGIN_STORAGE=database` and `MAGIC_LOGIN_STORAGE=cache`; both must pass

## Translations

Every user-facing string goes into all three language files (`resources/lang/en`, `es`, `ca`). A test asserts they carry identical keys.

## Local testing

There is no host app, so there is no `routes/autologin.php` and no local browser testing. Verify behaviour through Pest tests.
