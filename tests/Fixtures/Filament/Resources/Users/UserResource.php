<?php

namespace Arzcode\FilamentMagicLogin\Tests\Fixtures\Filament\Resources\Users;

use Arzcode\FilamentMagicLogin\Tests\Fixtures\Filament\Resources\Users\Pages\EditUser;
use Arzcode\FilamentMagicLogin\Tests\Fixtures\Filament\Resources\Users\Pages\ListUsers;
use Arzcode\FilamentMagicLogin\Tests\Fixtures\Filament\Resources\Users\Pages\ViewUser;
use Arzcode\FilamentMagicLogin\Tests\Fixtures\Filament\Resources\Users\Tables\UsersTable;
use Arzcode\FilamentMagicLogin\Tests\Fixtures\Models\User;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name'),
            TextInput::make('email'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'view' => ViewUser::route('/{record}'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
