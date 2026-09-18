<?php

namespace EdrisaTuray\FilamentAzureAuth;

use DutchCodingCompany\FilamentSocialite\FilamentSocialitePlugin;
use DutchCodingCompany\FilamentSocialite\Provider;
use EdrisaTuray\FilamentAzureAuth\Models\AzureIdentity;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User;
use LogicException;

class AzureAuthPlugin extends FilamentSocialitePlugin
{
    public function register(Panel $panel): void
    {
        parent::register($panel);

        $guard = $panel->getAuthGuard();
        $provider = config("auth.guards.{$guard}.provider");
        $this->userModelClass(config("auth.providers.{$provider}.model"));
        $this->socialiteUserModelClass(AzureIdentity::class);
        $this->providers([
            Provider::make('filament-azure')
                ->label('Sign in with Microsoft')
                ->scopes(['User.Read'])
                ->with(['prompt' => 'select_account']),
        ]);
        $this->registration(fn (?Authenticatable $user): bool => $user !== null || (bool) config('filament-azure-auth.auto_provision'));
        $this->resolveUserUsing($this->resolveUserUsing ?? function (User $oauthUser): ?Authenticatable {
            if (! config('filament-azure-auth.link_existing_users')) {
                return null;
            }

            $model = $this->getUserModelClass();

            return $model::query()->where('email', $oauthUser->getEmail())->first();
        });
        $this->authorizeUserUsing($this->authorizeUserUsing ?? function (User $oauthUser): bool {
            return filled($oauthUser->getId())
                && filter_var($oauthUser->getEmail(), FILTER_VALIDATE_EMAIL) !== false
                && static::checkDomainAllowList($this, $oauthUser);
        });
        $this->createUserUsing($this->createUserUsing ?? function (User $oauthUser): Authenticatable {
            $model = $this->getUserModelClass();
            if ($model::query()->where('email', $oauthUser->getEmail())->exists()) {
                throw new Exceptions\LoginDenied('This account has not been linked to Microsoft. Contact your administrator.');
            }

            $user = new $model;
            $user->forceFill([
                'name' => $oauthUser->getName() ?: $oauthUser->getEmail(),
                'email' => $oauthUser->getEmail(),
                'password' => Hash::make(Str::random(64)),
            ])->save();

            $user->refresh();

            if ($role = config('filament-azure-auth.default_role')) {
                if (! method_exists($user, 'assignRole')) {
                    throw new LogicException('AZURE_DEFAULT_ROLE requires a user model with assignRole().');
                }
                $user->assignRole($role);
            }

            app(EnsurePanelAccess::class)->handle($user, $this->getPanel());

            return $user;
        });
    }

    public function isConfigured(): bool
    {
        $settings = config('filament-azure-auth');

        return Str::isUuid($settings['tenant'] ?? '')
            && filled($settings['client_id'] ?? null)
            && filled($settings['client_secret'] ?? null)
            && filter_var($settings['redirect'] ?? '', FILTER_VALIDATE_URL) !== false;
    }
}
