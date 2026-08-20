<?php

namespace Arzcode\FilamentMagicLogin\Tests\Fixtures\MultiFactor;

use Filament\Auth\MultiFactor\Contracts\MultiFactorAuthenticationProvider;
use Filament\Forms\Components\TextInput;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A minimal always-enabled provider, used to observe how the login page's
 * multi-factor challenge interacts with magic-link authentication.
 */
class AlwaysOnMultiFactorProvider implements MultiFactorAuthenticationProvider
{
    /**
     * Whether the user is treated as having enrolled a second factor.
     */
    public static bool $enabled = true;

    public function isEnabled(Authenticatable $user): bool
    {
        return static::$enabled;
    }

    public function getId(): string
    {
        return 'always-on';
    }

    public function getLoginFormLabel(): string
    {
        return 'Always on';
    }

    /**
     * @return array<mixed>
     */
    public function getManagementSchemaComponents(): array
    {
        return [];
    }

    /**
     * @return array<mixed>
     */
    public function getChallengeFormComponents(Authenticatable $user): array
    {
        return [
            TextInput::make('code')->required(),
        ];
    }
}
