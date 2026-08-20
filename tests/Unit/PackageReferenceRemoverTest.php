<?php

use Arzcode\FilamentMagicLogin\Data\CleanedSource;
use Arzcode\FilamentMagicLogin\Support\PackageReferenceRemover;

function clean(string $code): CleanedSource
{
    return (new PackageReferenceRemover)->remove($code);
}

it('leaves files that never mention the package alone', function (): void {
    $code = <<<'PHP'
    <?php

    class AdminPanelProvider
    {
        public function panel($panel)
        {
            return $panel->login();
        }
    }
    PHP;

    $result = clean($code);

    expect($result->code)->toBe($code)
        ->and($result->changed)->toBeFalse()
        ->and($result->isClean())->toBeTrue();
});

it('removes a single line plugin registration and its import', function (): void {
    $result = clean(<<<'PHP'
    <?php

    use Arzcode\FilamentMagicLogin\MagicLoginPlugin;
    use Filament\Panel;

    class AdminPanelProvider
    {
        public function panel(Panel $panel): Panel
        {
            return $panel
                ->id('admin')
                ->login()
                ->plugin(MagicLoginPlugin::make())
                ->authGuard('web');
        }
    }
    PHP);

    expect($result->changed)->toBeTrue()
        ->and($result->isClean())->toBeTrue()
        ->and($result->code)->not->toContain('MagicLoginPlugin')
        ->and($result->code)->toContain("->login()\n            ->authGuard('web')")
        ->and($result->code)->toContain('use Filament\Panel;');
});

it('removes a multi line fluent registration', function (): void {
    $result = clean(<<<'PHP'
    <?php

    use Arzcode\FilamentMagicLogin\Enums\MagicLinkPosition;
    use Arzcode\FilamentMagicLogin\MagicLoginPlugin;

    class AdminPanelProvider
    {
        public function panel($panel)
        {
            return $panel
                ->login()
                ->plugin(
                    MagicLoginPlugin::make()
                        ->expiresAfter(minutes: 10)
                        ->position(MagicLinkPosition::EmailFieldHint)
                        ->redirectTo(fn () => route('filament.admin.pages.dashboard')),
                )
                ->authGuard('web');
        }
    }
    PHP);

    expect($result->changed)->toBeTrue()
        ->and($result->isClean())->toBeTrue()
        ->and($result->code)->not->toContain('MagicLoginPlugin')
        ->and($result->code)->not->toContain('MagicLinkPosition')
        ->and($result->code)->toContain("->login()\n            ->authGuard('web')");
});

it('removes only our entry from a plugins array', function (): void {
    $result = clean(<<<'PHP'
    <?php

    use Arzcode\FilamentMagicLogin\MagicLoginPlugin;

    class AdminPanelProvider
    {
        public function panel($panel)
        {
            return $panel->plugins([
                OtherPlugin::make(),
                MagicLoginPlugin::make()->expiresAfter(10),
                ThirdPlugin::make(),
            ]);
        }
    }
    PHP);

    expect($result->changed)->toBeTrue()
        ->and($result->isClean())->toBeTrue()
        ->and($result->code)->not->toContain('MagicLoginPlugin')
        ->and($result->code)->toContain('OtherPlugin::make()')
        ->and($result->code)->toContain('ThirdPlugin::make()');
});

it('removes the whole plugins call when ours was the only entry', function (): void {
    $result = clean(<<<'PHP'
    <?php

    use Arzcode\FilamentMagicLogin\MagicLoginPlugin;

    class AdminPanelProvider
    {
        public function panel($panel)
        {
            return $panel
                ->login()
                ->plugins([MagicLoginPlugin::make()])
                ->authGuard('web');
        }
    }
    PHP);

    expect($result->changed)->toBeTrue()
        ->and($result->isClean())->toBeTrue()
        ->and($result->code)->not->toContain('plugins')
        ->and($result->code)->toContain("->login()\n            ->authGuard('web')");
});

it('handles an aliased import', function (): void {
    $result = clean(<<<'PHP'
    <?php

    use Arzcode\FilamentMagicLogin\MagicLoginPlugin as MagicLogin;

    class AdminPanelProvider
    {
        public function panel($panel)
        {
            return $panel->login()->plugin(MagicLogin::make());
        }
    }
    PHP);

    expect($result->changed)->toBeTrue()
        ->and($result->isClean())->toBeTrue()
        ->and($result->code)->not->toContain('MagicLogin');
});

it('handles a fully qualified registration with no import', function (): void {
    $result = clean(<<<'PHP'
    <?php

    class AdminPanelProvider
    {
        public function panel($panel)
        {
            return $panel
                ->login()
                ->plugin(\Arzcode\FilamentMagicLogin\MagicLoginPlugin::make())
                ->authGuard('web');
        }
    }
    PHP);

    expect($result->changed)->toBeTrue()
        ->and($result->isClean())->toBeTrue()
        ->and($result->code)->not->toContain('FilamentMagicLogin');
});

it('removes the trait from a custom login page', function (): void {
    $result = clean(<<<'PHP'
    <?php

    namespace App\Filament\Pages;

    use Arzcode\FilamentMagicLogin\Concerns\HasMagicLinkAction;
    use Filament\Auth\Pages\Login as BaseLogin;

    class Login extends BaseLogin
    {
        use HasMagicLinkAction;

        public function getTitle(): string
        {
            return 'Sign in';
        }
    }
    PHP);

    expect($result->changed)->toBeTrue()
        ->and($result->isClean())->toBeTrue()
        ->and($result->code)->not->toContain('HasMagicLinkAction')
        ->and($result->code)->toContain('class Login extends BaseLogin')
        ->and($result->code)->toContain('use Filament\Auth\Pages\Login as BaseLogin;')
        // The blank line after the namespace survives, and none is left after the brace.
        ->and($result->code)->toContain("namespace App\Filament\Pages;\n\nuse Filament")
        ->and($result->code)->toContain("class Login extends BaseLogin\n{\n    public function");
});

it('leaves a grouped trait use for the developer', function (): void {
    $code = <<<'PHP'
    <?php

    use Arzcode\FilamentMagicLogin\Concerns\HasMagicLinkAction;

    class Login
    {
        use HasMagicLinkAction, SomethingElse;
    }
    PHP;

    $result = clean($code);

    // Rewriting a grouped `use` is not safe to guess at, so it is reported instead.
    expect($result->isClean())->toBeFalse()
        ->and($result->unresolvedLines)->not->toBeEmpty();
});

it('keeps an import that is still used elsewhere', function (): void {
    $result = clean(<<<'PHP'
    <?php

    use Arzcode\FilamentMagicLogin\Events\MagicLinkConsumed;

    class AuditListener
    {
        public function handle(MagicLinkConsumed $event): void
        {
            //
        }
    }
    PHP);

    // Nothing here is a plugin registration, so it is left intact and reported.
    expect($result->changed)->toBeFalse()
        ->and($result->isClean())->toBeFalse();
});

it('never returns unparsable code', function (string $code): void {
    $result = clean($code);

    expect((new PackageReferenceRemover)->isParsable($result->code))->toBeTrue();
})->with([
    'plugin call' => ["<?php\nuse Arzcode\\FilamentMagicLogin\\MagicLoginPlugin;\nclass A { function p(\$p) { return \$p->login()->plugin(MagicLoginPlugin::make())->x(); } }"],
    'plugins array' => ["<?php\nuse Arzcode\\FilamentMagicLogin\\MagicLoginPlugin;\nclass A { function p(\$p) { return \$p->plugins([MagicLoginPlugin::make(), Other::make()]); } }"],
    'trait' => ["<?php\nuse Arzcode\\FilamentMagicLogin\\Concerns\\HasMagicLinkAction;\nclass A { use HasMagicLinkAction; }"],
    'closure use' => ["<?php\nuse Arzcode\\FilamentMagicLogin\\MagicLoginPlugin;\nclass A { function p(\$p) { \$x = 1; return \$p->plugin(MagicLoginPlugin::make()->redirectTo(function () use (\$x) { return \$x; })); } }"],
]);
