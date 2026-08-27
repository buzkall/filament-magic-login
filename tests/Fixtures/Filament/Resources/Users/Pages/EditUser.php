<?php

namespace Arzcode\FilamentMagicLogin\Tests\Fixtures\Filament\Resources\Users\Pages;

use Arzcode\FilamentMagicLogin\Actions\SendMagicLinkAction;
use Arzcode\FilamentMagicLogin\Tests\Fixtures\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    public static ?\Closure $configureAction = null;

    protected function getHeaderActions(): array
    {
        $action = SendMagicLinkAction::make();

        if (static::$configureAction !== null) {
            $action = (static::$configureAction)($action);
        }

        return [$action];
    }
}
