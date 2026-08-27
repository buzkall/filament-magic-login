<?php

namespace Arzcode\FilamentMagicLogin\Tests\Fixtures\Filament\Resources\Users\Pages;

use Arzcode\FilamentMagicLogin\Tests\Fixtures\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;
}
