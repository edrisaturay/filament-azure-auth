<?php

namespace EdrisaTuray\FilamentAzureAuth\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use DutchCodingCompany\FilamentSocialite\FilamentSocialiteServiceProvider;
use EdrisaTuray\FilamentAzureAuth\AzureAuthServiceProvider;
use EdrisaTuray\FilamentAzureAuth\Tests\Fixtures\AdminPanelProvider;
use EdrisaTuray\FilamentAzureAuth\Tests\Fixtures\User;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Contracts\Factory;
use Laravel\Socialite\SocialiteServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use SocialiteProviders\Azure\Provider;
use SocialiteProviders\Manager\Config;
use SocialiteProviders\Manager\ServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            SupportServiceProvider::class,
            LivewireServiceProvider::class,
            ActionsServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            FilamentServiceProvider::class,
            SocialiteServiceProvider::class,
            ServiceProvider::class,
            AzureAuthServiceProvider::class,
            AdminPanelProvider::class,
            FilamentSocialiteServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('session.driver', 'array');
        $app['config']->set('filament-azure-auth', [
            'tenant' => '11111111-1111-4111-8111-111111111111',
            'client_id' => 'client-id',
            'client_secret' => 'test-secret',
            'redirect' => 'http://localhost/admin/oauth/callback/filament-azure',
            'auto_provision' => false,
            'link_existing_users' => false,
            'default_role' => null,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('allowed')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function fakeMicrosoft(string $email = 'person@example.org', string $objectId = 'object-123', int $status = 200): MockHandler
    {
        $handler = new MockHandler([
            new Response($status, [], json_encode(['access_token' => 'fake-token', 'token_type' => 'Bearer', 'expires_in' => 3600])),
            new Response(200, [], json_encode(['id' => $objectId, 'displayName' => 'Example Person', 'userPrincipalName' => $email, 'mail' => $email])),
        ]);
        $manager = $this->app->make(Factory::class);
        $manager->forgetDrivers();
        $manager->extend('filament-azure', function () use ($manager, $handler): Provider {
            $settings = config('filament-azure-auth');
            $provider = $manager->buildProvider(Provider::class, $settings);
            $provider->setConfig(new Config($settings['client_id'], $settings['client_secret'], $settings['redirect'], ['tenant' => $settings['tenant']]));
            $provider->setHttpClient(new Client(['handler' => HandlerStack::create($handler)]));

            return $provider;
        });

        return $handler;
    }

    protected function completeAzureCallback(string $state = 'test-state'): TestResponse
    {
        return $this->withSession(['state' => 'test-state'])
            ->get('/admin/oauth/callback/filament-azure?code=test-code&state='.$state);
    }
}
