# filament-magic-login

A Filament plugin package (no app), tested with Orchestra Testbench.

## Before finishing a change

Run these in order; CI enforces all three:

1. `composer format` — Pint, `laravel` preset
2. `composer analyse` — Larastan at level 10
3. `composer test` — runs the suite twice, with `MAGIC_LOGIN_STORAGE=database` and `MAGIC_LOGIN_STORAGE=cache`; both must pass

## Static analysis (PHPStan level 10)

Write code that passes level 10 on the first run; don't rely on `composer analyse` to catch it afterwards.

- Every method has native parameter and return types; use generics in PHPDoc where native types fall short (`array<string, mixed>`, `Collection<int, User>`, `Builder<Model>`).
- No `mixed` leaking through: narrow values from `config()`, `request()`, `cache()`, `json_decode()`, etc. with explicit checks (`is_string()`, `instanceof`) or typed helpers (`Config::string()`, `$request->string()`), not `@var` casts.
- Don't add `@phpstan-ignore` or baseline entries to silence errors; fix the type instead. If an ignore is truly unavoidable, use the identifier form and explain why.
- Model properties are checked (`checkModelProperties: true`): document attributes with `@property` on models.
- Code must be Octane-safe (`checkOctaneCompatibility: true`): no mutable static state or container-resolved singletons captured in constructors.

## Translations

Every user-facing string goes into all three language files (`resources/lang/en`, `es`, `ca`). A test asserts they carry identical keys.

## Local testing

There is no host app, so there is no `routes/autologin.php` and no local browser testing. Verify behaviour through Pest tests.
