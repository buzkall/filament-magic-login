<?php

namespace Arzcode\FilamentMagicLogin\Pages;

use Arzcode\FilamentMagicLogin\Concerns\HasMagicLinkAction;
use Filament\Auth\Pages\Login as BaseLogin;

class Login extends BaseLogin
{
    use HasMagicLinkAction;
}
