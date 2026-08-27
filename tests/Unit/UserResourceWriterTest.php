<?php

use Arzcode\FilamentMagicLogin\Support\UserResourceWriter;

beforeEach(function (): void {
    $this->writer = new UserResourceWriter;
});

function extractedTable(string $recordActions = "                EditAction::make(),\n"): string
{
    return <<<PHP
    <?php

    namespace App\Filament\Resources\Users\Tables;

    use Filament\Actions\EditAction;
    use Filament\Tables\Table;

    class UsersTable
    {
        public static function configure(Table \$table): Table
        {
            return \$table
                ->columns([])
                ->recordActions([
    {$recordActions}            ]);
        }
    }

    PHP;
}

function recordPage(string $headerActions = "            DeleteAction::make(),\n", string $base = 'ViewRecord'): string
{
    return <<<PHP
    <?php

    namespace App\Filament\Resources\Users\Pages;

    use App\Filament\Resources\Users\UserResource;
    use Filament\Actions\DeleteAction;
    use Filament\Resources\Pages\\{$base};

    class TheRecordPage extends {$base}
    {
        protected static string \$resource = UserResource::class;

        protected function getHeaderActions(): array
        {
            return [
    {$headerActions}        ];
        }
    }

    PHP;
}

it('appends the action to an extracted table class', function (): void {
    $result = $this->writer->addRecordAction(extractedTable());

    expect($result)->not->toBeNull()
        ->and(php_syntax_ok($result))->toBeTrue()
        ->and($result)->toContain('use Arzcode\FilamentMagicLogin\Actions\SendMagicLinkAction;')
        ->and($result)->toContain("                EditAction::make(),\n                SendMagicLinkAction::make(),")
        // The existing action is left exactly as it was.
        ->and($result)->toContain('EditAction::make()');
});

it('appends the action to a table written inline on the resource', function (): void {
    $code = <<<'PHP'
    <?php

    namespace App\Filament\Resources;

    use App\Models\User;
    use Filament\Actions\EditAction;
    use Filament\Resources\Resource;
    use Filament\Tables\Table;

    class UserResource extends Resource
    {
        protected static ?string $model = User::class;

        public static function table(Table $table): Table
        {
            return $table->recordActions([
                EditAction::make(),
            ]);
        }
    }

    PHP;

    $result = $this->writer->addRecordAction($code);

    expect($result)->not->toBeNull()
        ->and(php_syntax_ok($result))->toBeTrue()
        ->and($result)->toContain('SendMagicLinkAction::make(),');
});

it('appends the action to a record page header', function (string $base): void {
    $result = $this->writer->addHeaderAction(recordPage(base: $base));

    expect($result)->not->toBeNull()
        ->and(php_syntax_ok($result))->toBeTrue()
        ->and($result)->toContain("            DeleteAction::make(),\n            SendMagicLinkAction::make(),");
})->with(['ViewRecord', 'EditRecord']);

it('appends the action to an empty header actions array', function (): void {
    $code = str_replace("        return [\n            DeleteAction::make(),\n        ];", '        return [];', recordPage());

    $result = $this->writer->addHeaderAction($code);

    expect($result)->not->toBeNull()
        ->and(php_syntax_ok($result))->toBeTrue()
        ->and($result)->toContain('return [SendMagicLinkAction::make()];');
});

it('adds a trailing comma when the last element lacks one', function (): void {
    $result = $this->writer->addRecordAction(extractedTable("                EditAction::make()\n"));

    expect($result)->not->toBeNull()
        ->and(php_syntax_ok($result))->toBeTrue()
        ->and($result)->toContain("                EditAction::make(),\n                SendMagicLinkAction::make(),");
});

it('leaves a table alone when the actions come from a variable', function (): void {
    $code = str_replace(
        "->recordActions([\n                EditAction::make(),\n            ]);",
        '->recordActions($actions);',
        extractedTable(),
    );

    expect($this->writer->addRecordAction($code))->toBeNull();
});

it('leaves a file alone when it configures two tables', function (): void {
    $code = str_replace(
        '    }
}',
        '    }

    public static function other(Table $table): Table
    {
        return $table->recordActions([]);
    }
}',
        extractedTable(),
    );

    expect($this->writer->addRecordAction($code))->toBeNull();
});

it('leaves a page alone when getHeaderActions returns a variable', function (): void {
    $code = str_replace("        return [\n            DeleteAction::make(),\n        ];", '        return $actions;', recordPage());

    expect($this->writer->addHeaderAction($code))->toBeNull();
});

it('leaves a page alone when it has no getHeaderActions at all', function (): void {
    $code = <<<'PHP'
    <?php

    namespace App\Filament\Resources\Users\Pages;

    use Filament\Resources\Pages\ViewRecord;

    class ViewUser extends ViewRecord
    {
    }

    PHP;

    expect($this->writer->addHeaderAction($code))->toBeNull();
});

it('refuses to import over an unrelated class of the same name', function (): void {
    $code = str_replace(
        'use Filament\Actions\EditAction;',
        "use Filament\Actions\EditAction;\nuse App\Support\SendMagicLinkAction;",
        extractedTable(),
    );

    expect($this->writer->addRecordAction($code))->toBeNull();
});

it('is idempotent', function (): void {
    $once = $this->writer->addRecordAction(extractedTable());

    expect($this->writer->isWired(extractedTable()))->toBeFalse()
        ->and($this->writer->isWired($once))->toBeTrue()
        ->and($this->writer->addRecordAction($once))->toBeNull();
});

it('recognises a resource by the model it declares, through the file imports', function (): void {
    $code = <<<'PHP'
    <?php

    namespace App\Filament\Resources;

    use App\Models\User;
    use Filament\Resources\Resource;

    class UserResource extends Resource
    {
        protected static ?string $model = User::class;
    }

    PHP;

    expect($this->writer->isResourceFor($code, 'App\Models\User'))->toBeTrue()
        ->and($this->writer->isResourceFor($code, 'App\Models\Admin'))->toBeFalse();
});

it('recognises a resource whose model is written out in full', function (): void {
    $code = <<<'PHP'
    <?php

    namespace App\Filament\Resources;

    use Filament\Resources\Resource;

    class UserResource extends Resource
    {
        protected static ?string $model = \App\Models\User::class;
    }

    PHP;

    expect($this->writer->isResourceFor($code, 'App\Models\User'))->toBeTrue();
});

it('does not mistake a page or a plain class for a resource', function (): void {
    expect($this->writer->isResourceFor(recordPage(), 'App\Models\User'))->toBeFalse();
});

it('recognises the record pages of a resource', function (): void {
    expect($this->writer->isRecordPageFor(recordPage(), 'App\Filament\Resources\Users\UserResource'))->toBeTrue()
        ->and($this->writer->isRecordPageFor(recordPage(), 'App\Filament\Resources\Other\OtherResource'))->toBeFalse()
        ->and($this->writer->isRecordPageFor(extractedTable(), 'App\Filament\Resources\Users\UserResource'))->toBeFalse();
});

it('does not treat a list page as a record page', function (): void {
    $code = <<<'PHP'
    <?php

    namespace App\Filament\Resources\Users\Pages;

    use App\Filament\Resources\Users\UserResource;
    use Filament\Resources\Pages\ListRecords;

    class ListUsers extends ListRecords
    {
        protected static string $resource = UserResource::class;
    }

    PHP;

    expect($this->writer->isRecordPageFor($code, 'App\Filament\Resources\Users\UserResource'))->toBeFalse();
});

it('reads the class a file declares', function (): void {
    expect($this->writer->declaredClass(extractedTable()))
        ->toBe('App\Filament\Resources\Users\Tables\UsersTable')
        ->and($this->writer->declaredClass('<?php $x = 1;'))->toBeNull();
});

it('is not fooled by a ::class constant when reading the declared class', function (): void {
    expect($this->writer->declaredClass(recordPage()))
        ->toBe('App\Filament\Resources\Users\Pages\TheRecordPage');
});

it('prints a snippet naming both placements', function (): void {
    expect($this->writer->snippet())
        ->toContain('use Arzcode\FilamentMagicLogin\Actions\SendMagicLinkAction;')
        ->toContain('recordActions')
        ->toContain('SendMagicLinkAction::make()');
});
