<?php

namespace Arzcode\FilamentMagicLogin\Support;

use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Support\Facades\Auth;

/**
 * Resolves the user provider behind a guard, so the host app's own model and any
 * custom provider are respected instead of a hard-coded App\Models\User.
 */
final readonly class UserProviderResolver
{
    public function for(string $guard): ?UserProvider
    {
        $instance = Auth::guard($guard);

        if (method_exists($instance, 'getProvider')) {
            $provider = $instance->getProvider();

            if ($provider instanceof UserProvider) {
                return $provider;
            }
        }

        $name = config("auth.guards.{$guard}.provider");

        return is_string($name) ? Auth::createUserProvider($name) : null;
    }
}
