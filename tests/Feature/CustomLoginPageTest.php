<?php

use Arzcode\FilamentMagicLogin\Pages\Login as MagicLogin;
use Arzcode\FilamentMagicLogin\Tests\Fixtures\Pages\CustomLoginWithoutTrait;
use Arzcode\FilamentMagicLogin\Tests\Fixtures\Pages\CustomLoginWithTrait;
use Arzcode\FilamentMagicLogin\Tests\Fixtures\Panels\CustomLoginPanelProvider;
use Arzcode\FilamentMagicLogin\Tests\TestCase;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;

beforeEach(function (): void {
    TestCase::$registerCustomLoginPanel = true;
});

it('swaps the stock login page whichever order the chain is written in', function (bool $pluginFirst): void {
    CustomLoginPanelProvider::$loginPage = BaseLogin::class;
    CustomLoginPanelProvider::$pluginBeforeLogin = $pluginFirst;

    $this->rebootWith();

    expect(Filament::getPanel('custom')->getLoginRouteAction())->toBe(MagicLogin::class);
})->with([
    'login() before plugin()' => false,
    'plugin() before login()' => true,
]);

// 27
it('throws when a custom login page does not use the trait', function (bool $pluginFirst): void {
    CustomLoginPanelProvider::$loginPage = CustomLoginWithoutTrait::class;
    CustomLoginPanelProvider::$pluginBeforeLogin = $pluginFirst;

    expect(fn () => $this->rebootWith())
        ->toThrow(
            LogicException::class,
            'does not use Arzcode\FilamentMagicLogin\Concerns\HasMagicLinkAction',
        );
})->with([
    'login() before plugin()' => false,
    'plugin() before login()' => true,
]);

it('leaves a custom login page that uses the trait alone', function (): void {
    CustomLoginPanelProvider::$loginPage = CustomLoginWithTrait::class;

    $this->rebootWith();

    expect(Filament::getPanel('custom')->getLoginRouteAction())->toBe(CustomLoginWithTrait::class);
});

it('leaves any login page alone when useCustomLoginPage is set', function (): void {
    CustomLoginPanelProvider::$loginPage = CustomLoginWithoutTrait::class;
    CustomLoginPanelProvider::$configurePlugin = fn ($plugin) => $plugin->useCustomLoginPage();

    $this->rebootWith();

    expect(Filament::getPanel('custom')->getLoginRouteAction())->toBe(CustomLoginWithoutTrait::class);
});

it('serves the magic link action from a custom page using the trait', function (): void {
    CustomLoginPanelProvider::$loginPage = CustomLoginWithTrait::class;

    $this->rebootWith();

    Filament::setCurrentPanel('custom');

    Pest\Livewire\livewire(CustomLoginWithTrait::class)
        ->assertActionExists('magicLink');
});
