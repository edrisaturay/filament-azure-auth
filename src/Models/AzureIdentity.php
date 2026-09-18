<?php

namespace EdrisaTuray\FilamentAzureAuth\Models;

use DutchCodingCompany\FilamentSocialite\FilamentSocialitePlugin;
use DutchCodingCompany\FilamentSocialite\Models\Contracts\FilamentSocialiteUser;
use EdrisaTuray\FilamentAzureAuth\Exceptions\LoginDenied;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Laravel\Socialite\Contracts\User;

class AzureIdentity extends Model implements FilamentSocialiteUser
{
    protected $fillable = ['tenant_id', 'object_id', 'user_type', 'user_id'];

    public function getUser(): Authenticatable
    {
        $model = FilamentSocialitePlugin::current()->getUserModelClass();
        $user = $model === $this->user_type ? $model::query()->find($this->user_id) : null;
        if (! $user instanceof Authenticatable) {
            throw new LoginDenied('Your linked account is unavailable. Contact your administrator.');
        }

        return $user;
    }

    public static function findForProvider(string $provider, User $oauthUser): ?self
    {
        return static::query()
            ->where('tenant_id', strtolower(config('filament-azure-auth.tenant')))
            ->where('object_id', $oauthUser->getId())
            ->where('user_type', FilamentSocialitePlugin::current()->getUserModelClass())
            ->first();
    }

    public static function createForProvider(string $provider, User $oauthUser, Authenticatable $user): self
    {
        return static::query()->create([
            'tenant_id' => strtolower(config('filament-azure-auth.tenant')),
            'object_id' => $oauthUser->getId(),
            'user_type' => $user::class,
            'user_id' => (string) $user->getAuthIdentifier(),
        ]);
    }
}
