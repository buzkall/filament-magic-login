<?php

namespace Arzcode\FilamentMagicLogin\Tests\Fixtures\Filament\Resources\Users\Tables;

use Arzcode\FilamentMagicLogin\Actions\SendMagicLinkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersTable
{
    /**
     * Configured through a static closure so a test can swap the action out for one it
     * has set up differently, the way an application would configure it by hand.
     */
    public static ?\Closure $configureAction = null;

    public static function configure(Table $table): Table
    {
        $action = SendMagicLinkAction::make();

        if (static::$configureAction !== null) {
            $action = (static::$configureAction)($action);
        }

        return $table
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('email'),
            ])
            ->recordActions([
                EditAction::make(),
                $action,
            ]);
    }
}
