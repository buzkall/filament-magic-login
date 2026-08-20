<?php

namespace Arzcode\FilamentMagicLogin\Tests\Fixtures\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];

    protected $hidden = ['password'];

    public function canAccessPanel(Panel $panel): bool
    {
        return (bool) ($this->can_access ?? true);
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'can_access' => 'bool',
        ];
    }
}
