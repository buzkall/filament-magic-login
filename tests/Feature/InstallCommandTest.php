<?php

it('runs the install command for the database driver', function (): void {
    config()->set('filament-magic-login.storage.driver', 'database');

    $this->artisan('filament-magic-login:install')
        ->expectsQuestion('Would you like to run the migrations now?', false)
        ->expectsQuestion('Would you like to star our repo on GitHub?', false)
        ->assertSuccessful();
});

it('skips the migration prompts for the cache driver', function (): void {
    config()->set('filament-magic-login.storage.driver', 'cache');

    $this->artisan('filament-magic-login:install')
        ->expectsOutputToContain(__('filament-magic-login::filament-magic-login.install.cache_driver_skip_migrations'))
        ->expectsQuestion('Would you like to star our repo on GitHub?', false)
        ->assertSuccessful();
});
