<?php

use Arzcode\FilamentMagicLogin\Support\PluginRegistrationWriter;

beforeEach(function (): void {
    $this->writer = new PluginRegistrationWriter;
});

function providerSource(string $chain, string $imports = ''): string
{
    return <<<PHP
    <?php

    namespace App\Providers\Filament;

    use Filament\Panel;
    use Filament\PanelProvider;
    {$imports}
    class AdminPanelProvider extends PanelProvider
    {
        public function panel(Panel \$panel): Panel
        {
    {$chain}
        }
    }
    PHP;
}

it('appends the registration to the returned chain', function (): void {
    $code = providerSource(<<<'PHP'
            return $panel
                ->default()
                ->id('admin')
                ->path('admin')
                ->login();
    PHP);

    $result = $this->writer->add($code);

    expect($result)->not->toBeNull()
        ->and($this->writer->isParsable($result))->toBeTrue()
        // Indented like the calls it joins, and last in the chain.
        ->and($result)->toContain("            ->login()\n            ->plugin(MagicLoginPlugin::make());")
        ->and($result)->toContain('use Arzcode\FilamentMagicLogin\MagicLoginPlugin;');
});

it('is not fooled by the indentation of a call argument', function (): void {
    $code = providerSource(<<<'PHP'
            return $panel
                ->id('admin')
                ->authMiddleware([
                    Authenticate::class,
                ]);
    PHP);

    $result = $this->writer->add($code);

    // The array's own indentation is one level deeper; the chain's is what counts.
    expect($result)->not->toBeNull()
        ->and($this->writer->isParsable($result))->toBeTrue()
        ->and($result)->toContain("            ])\n            ->plugin(MagicLoginPlugin::make());");
});

it('appends inline when the chain is written on one line', function (): void {
    $code = providerSource("        return \$panel->id('admin')->login();");

    $result = $this->writer->add($code);

    expect($result)->not->toBeNull()
        ->and($this->writer->isParsable($result))->toBeTrue()
        ->and($result)->toContain("return \$panel->id('admin')->login()->plugin(MagicLoginPlugin::make());");
});

it('handles a provider that returns the variable on its own', function (): void {
    $code = providerSource(<<<'PHP'
            $panel->id('admin');

            return $panel;
    PHP);

    $result = $this->writer->add($code);

    expect($result)->not->toBeNull()
        ->and($this->writer->isParsable($result))->toBeTrue()
        ->and($result)->toContain('return $panel->plugin(MagicLoginPlugin::make());');
});

it('reuses an import the provider already has', function (): void {
    $code = providerSource(
        "        return \$panel->id('admin');",
        "use Arzcode\\FilamentMagicLogin\\MagicLoginPlugin;\n",
    );

    $result = $this->writer->add($code);

    expect(substr_count((string) $result, 'use Arzcode\FilamentMagicLogin\MagicLoginPlugin;'))->toBe(1)
        ->and($this->writer->isParsable($result))->toBeTrue();
});

it('refuses to write when another class already owns the short name', function (): void {
    // Importing ours as well would be a fatal "name already in use".
    $code = providerSource(
        "        return \$panel->id('admin');",
        "use App\\Filament\\MagicLoginPlugin;\n",
    );

    expect($this->writer->add($code))->toBeNull();
});

it('refuses a provider whose returned chain it cannot find', function (): void {
    $code = providerSource('        return $this->configure();');

    expect($this->writer->add($code))->toBeNull();
});

it('refuses a provider that returns the panel in more than one place', function (): void {
    // Two exits mean two candidate chains, and no way to be certain which is taken.
    $code = providerSource(<<<'PHP'
            if ($panel->getId() === 'admin') {
                return $panel->login();
            }

            return $panel;
    PHP);

    expect($this->writer->add($code))->toBeNull();
});

it('refuses to write to a file that does not parse', function (): void {
    expect($this->writer->add('<?php class {'))->toBeNull();
});

it('detects an existing registration whatever shape it was written in', function (): void {
    expect($this->writer->isRegistered('$panel->plugin(MagicLoginPlugin::make());'))->toBeTrue()
        ->and($this->writer->isRegistered('$panel->plugins([MagicLoginPlugin::make(), Other::make()]);'))->toBeTrue()
        ->and($this->writer->isRegistered('$panel->plugin(\Arzcode\FilamentMagicLogin\MagicLoginPlugin::make());'))->toBeTrue()
        ->and($this->writer->isRegistered('$panel->plugin(OtherPlugin::make());'))->toBeFalse()
        ->and($this->writer->isRegistered("<?php\n"))->toBeFalse();
});

it('recognises a panel provider by its shape', function (): void {
    expect($this->writer->isPanelProvider(providerSource('        return $panel;')))->toBeTrue()
        ->and($this->writer->isPanelProvider("<?php\n\nclass AdminPanelProvider {}"))->toBeFalse()
        ->and($this->writer->isPanelProvider("<?php\n\nclass Order { public function panel() {} }"))->toBeFalse();
});

it('appends alongside a plugin the panel already registers', function (): void {
    $code = providerSource(<<<'PHP'
            return $panel
                ->id('admin')
                ->plugin(FilamentShieldPlugin::make())
                ->login();
    PHP);

    $result = $this->writer->add($code);

    // Filament's plugin() keys by plugin id, so a second call sits happily beside
    // the first; the one already there is left exactly as it was.
    expect($result)->not->toBeNull()
        ->and($this->writer->isParsable($result))->toBeTrue()
        ->and($result)->toContain('->plugin(FilamentShieldPlugin::make())')
        ->and($result)->toContain("            ->login()\n            ->plugin(MagicLoginPlugin::make());");
});

it('steps over a plugins array the panel already has', function (): void {
    $code = providerSource(<<<'PHP'
            return $panel
                ->id('admin')
                ->plugins([
                    FilamentShieldPlugin::make(),
                    ThemesPlugin::make(),
                ]);
    PHP);

    $result = $this->writer->add($code);

    expect($result)->not->toBeNull()
        ->and($this->writer->isParsable($result))->toBeTrue()
        ->and($result)->toContain("                ThemesPlugin::make(),\n            ])\n            ->plugin(MagicLoginPlugin::make());");
});

it('is not fooled by a plugin configured inside a plugins array', function (): void {
    // The array is last in the chain, so the deepest `->` in the file is the one
    // inside it — and it is no guide to where the panel's own calls are indented.
    $code = providerSource(<<<'PHP'
            return $panel
                ->id('admin')
                ->plugins([
                    FilamentShieldPlugin::make()
                        ->gridColumns(['default' => 1]),
                ]);
    PHP);

    $result = $this->writer->add($code);

    expect($result)->not->toBeNull()
        ->and($this->writer->isParsable($result))->toBeTrue()
        ->and($result)->toContain("            ])\n            ->plugin(MagicLoginPlugin::make());");
});

it('does not mistake another plugin for ours', function (): void {
    $code = providerSource('        return $panel->plugin(FilamentShieldPlugin::make());');

    expect($this->writer->isRegistered($code))->toBeFalse();
});
