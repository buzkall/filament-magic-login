<?php

use Arzcode\FilamentMagicLogin\Models\MagicLoginToken;

// 24
it('prunes tokens that expired more than a day ago and keeps newer ones', function (): void {
    $stale = MagicLoginToken::query()->create([
        'authenticatable_type' => 'user',
        'authenticatable_id' => 1,
        'token_hash' => str_repeat('a', 64),
        'panel_id' => 'admin',
        'guard' => 'web',
        'expires_at' => now()->subHours(25),
    ]);

    $recent = MagicLoginToken::query()->create([
        'authenticatable_type' => 'user',
        'authenticatable_id' => 1,
        'token_hash' => str_repeat('b', 64),
        'panel_id' => 'admin',
        'guard' => 'web',
        'expires_at' => now()->subHours(23),
    ]);

    $live = MagicLoginToken::query()->create([
        'authenticatable_type' => 'user',
        'authenticatable_id' => 1,
        'token_hash' => str_repeat('c', 64),
        'panel_id' => 'admin',
        'guard' => 'web',
        'expires_at' => now()->addMinutes(15),
    ]);

    expect((new MagicLoginToken)->prunable()->get()->pluck('id')->all())
        ->toBe([$stale->id]);

    $this->artisan('model:prune', ['--model' => [MagicLoginToken::class]])->assertSuccessful();

    expect(MagicLoginToken::query()->pluck('id')->all())
        ->toEqualCanonicalizing([$recent->id, $live->id]);
});

it('creates the token table once, so a republished migration is safe to run again', function (): void {
    $migration = include __DIR__.'/../../database/migrations/create_magic_login_tokens_table.php.stub';

    // An uninstall that kept the table, followed by an install: the migration comes back
    // under a new name and runs against a table that is already there.
    $migration->up();

    expect(MagicLoginToken::query()->count())->toBe(0);
});
