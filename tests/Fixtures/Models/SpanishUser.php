<?php

namespace Arzcode\FilamentMagicLogin\Tests\Fixtures\Models;

use Illuminate\Contracts\Translation\HasLocalePreference;

class SpanishUser extends User implements HasLocalePreference
{
    protected $table = 'users';

    public function preferredLocale(): string
    {
        return 'es';
    }
}
