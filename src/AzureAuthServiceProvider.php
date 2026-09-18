<?php

namespace EdrisaTuray\FilamentAzureAuth;

use DutchCodingCompany\FilamentSocialite\Http\Controllers\SocialiteLoginController;
use EdrisaTuray\FilamentAzureAuth\Commands\InstallCommand;
use EdrisaTuray\FilamentAzureAuth\Http\Controllers\AzureLoginController;
use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Contracts\Factory;
use SocialiteProviders\Azure\Provider;
use SocialiteProviders\Manager\Config;

class AzureAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/filament-azure-auth.php', 'filament-azure-auth');
        $this->app->bind(SocialiteLoginController::class, AzureLoginController::class);
    }

    public function boot(): void
    {
        config(['services.filament-azure' => config('filament-azure-auth')]);

        $this->app->afterResolving(Factory::class, function (Factory $socialite): void {
            $socialite->extend('filament-azure', function () use ($socialite): Provider {
                $settings = config('filament-azure-auth');
                $provider = $socialite->buildProvider(Provider::class, $settings);

                return $provider->setConfig(new Config(
                    $settings['client_id'],
                    $settings['client_secret'],
                    $settings['redirect'],
                    ['tenant' => $settings['tenant']],
                ));
            });
        });

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/filament-azure-auth.php' => config_path('filament-azure-auth.php'),
            ], 'filament-azure-auth-config');
            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'filament-azure-auth-migrations');
            $this->commands([InstallCommand::class]);
        }
    }
}
