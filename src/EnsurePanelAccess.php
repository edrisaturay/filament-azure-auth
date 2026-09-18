<?php

namespace EdrisaTuray\FilamentAzureAuth;

use EdrisaTuray\FilamentAzureAuth\Exceptions\LoginDenied;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;

class EnsurePanelAccess
{
    public function handle(Authenticatable $user, Panel $panel): void
    {
        if (! $user instanceof FilamentUser || ! $user->canAccessPanel($panel)) {
            throw new LoginDenied('Your account does not have access to this panel. Contact your administrator.');
        }

        foreach ($panel->getMultiFactorAuthenticationProviders() as $mfa) {
            if ($mfa->isEnabled($user)) {
                throw new LoginDenied('Use the standard sign-in form to complete your application two-factor authentication.');
            }
        }
    }
}
