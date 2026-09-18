<?php

namespace EdrisaTuray\FilamentAzureAuth\Http\Controllers;

use Closure;
use DutchCodingCompany\FilamentSocialite\Http\Controllers\SocialiteLoginController;
use DutchCodingCompany\FilamentSocialite\Models\Contracts\FilamentSocialiteUser;
use EdrisaTuray\FilamentAzureAuth\AzureAuthPlugin;
use EdrisaTuray\FilamentAzureAuth\EnsurePanelAccess;
use EdrisaTuray\FilamentAzureAuth\Exceptions\LoginDenied;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Contracts\User;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response;

class AzureLoginController extends SocialiteLoginController
{
    public function __construct()
    {
        $this->middleware(function (Request $request, Closure $next): mixed {
            if ($this->plugin() instanceof AzureAuthPlugin) {
                return app(ThrottleRequests::class)->handle($request, $next, 60, 1, 'filament-azure:');
            }

            return $next($request);
        });
    }

    public function redirectToProvider(string $provider): mixed
    {
        if ($this->plugin() instanceof AzureAuthPlugin && ! $this->plugin()->isConfigured()) {
            return $this->redirectToLogin('Microsoft sign-in is not configured. Contact your administrator.');
        }

        return parent::redirectToProvider($provider);
    }

    public function processCallback(string $provider): Response
    {
        if (! $this->plugin() instanceof AzureAuthPlugin) {
            return parent::processCallback($provider);
        }

        if (! $this->plugin()->isConfigured()) {
            return $this->redirectToLogin('Microsoft sign-in is not configured. Contact your administrator.');
        }

        if (request()->has('error')) {
            request()->session()->forget('state');

            return $this->redirectToLogin('Microsoft sign-in was cancelled or denied. Please try again.');
        }

        Socialite::forgetDrivers();

        try {
            return parent::processCallback($provider);
        } catch (LoginDenied $exception) {
            return $this->redirectToLogin($exception->getMessage());
        } catch (GuzzleException $exception) {
            Log::warning('Microsoft sign-in request failed.', ['exception_type' => $exception::class]);

            return $this->redirectToLogin('Microsoft sign-in is temporarily unavailable. Please try again.');
        }
    }

    protected function registerSocialiteUser(string $provider, User $oauthUser, Authenticatable $user): Response
    {
        if ($this->plugin() instanceof AzureAuthPlugin) {
            app(EnsurePanelAccess::class)->handle($user, $this->plugin()->getPanel());
        }

        return parent::registerSocialiteUser($provider, $oauthUser, $user);
    }

    protected function loginUser(string $provider, FilamentSocialiteUser $socialiteUser, User $oauthUser): Response
    {
        if (! $this->plugin() instanceof AzureAuthPlugin) {
            return parent::loginUser($provider, $socialiteUser, $oauthUser);
        }

        $user = $socialiteUser->getUser();
        $panel = $this->plugin()->getPanel();
        app(EnsurePanelAccess::class)->handle($user, $panel);

        request()->session()->regenerate();

        return parent::loginUser($provider, $socialiteUser, $oauthUser);
    }
}
