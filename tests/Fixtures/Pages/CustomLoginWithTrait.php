<?php

namespace Arzcode\FilamentMagicLogin\Tests\Fixtures\Pages;

use Arzcode\FilamentMagicLogin\Concerns\HasMagicLinkAction;
use Filament\Auth\Pages\Login;

class CustomLoginWithTrait extends Login
{
    use HasMagicLinkAction;
}
