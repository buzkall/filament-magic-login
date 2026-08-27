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

    /**
     * `can_access_panels` names the panels this user may reach, for the multi-panel
     * cases; `can_access` is the blunt all-or-nothing answer everywhere else.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if (is_array($this->can_access_panels)) {
            return in_array($panel->getId(), $this->can_access_panels, true);
        }

        return (bool) ($this->can_access ?? true);
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'can_access' => 'bool',
            'can_access_panels' => 'array',
        ];
    }
}
