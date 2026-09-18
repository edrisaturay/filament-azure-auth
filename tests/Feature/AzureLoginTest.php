<?php

use EdrisaTuray\FilamentAzureAuth\Models\AzureIdentity;
use EdrisaTuray\FilamentAzureAuth\Tests\Fixtures\User;
use Filament\Auth\MultiFactor\Contracts\MultiFactorAuthenticationProvider;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;

it('redirects to the configured tenant with state and the registered callback', function () {
    $response = $this->get('/admin/oauth/filament-azure')->assertRedirect();
    $url = $response->headers->get('Location');
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    expect($url)->toStartWith('https://login.microsoftonline.com/11111111-1111-4111-8111-111111111111/oauth2/v2.0/authorize');
    expect($query)->toMatchArray(['scope' => 'User.Read', 'prompt' => 'select_account', 'redirect_uri' => 'http://localhost/admin/oauth/callback/filament-azure']);
    $response->assertSessionHas('state', $query['state']);
});

it('rejects common tenant configuration', function () {
    config(['filament-azure-auth.tenant' => 'common']);
    $this->get('/admin/oauth/filament-azure')->assertRedirect('/admin/login')
        ->assertSessionHas('filament-socialite-login-error', 'Microsoft sign-in is not configured. Contact your administrator.');
});

it('rejects invalid state before contacting Microsoft', function () {
    $handler = $this->fakeMicrosoft();
    $this->completeAzureCallback('wrong-state')->assertRedirect('/admin/login')->assertSessionHas('filament-socialite-login-error');
    expect($handler->count())->toBe(2);
    $this->assertGuest();
    $this->assertDatabaseCount('azure_identities', 0);
});

it('does not create unknown users by default', function () {
    $this->fakeMicrosoft();
    $this->completeAzureCallback()->assertRedirect('/admin/login');
    $this->assertGuest();
    $this->assertDatabaseCount('users', 0);
});

it('does not link matching emails without explicit configuration', function () {
    $user = User::create(['name' => 'Existing', 'email' => 'person@example.org', 'password' => 'unused']);
    $this->fakeMicrosoft();
    $this->completeAzureCallback()->assertRedirect('/admin/login');
    $this->assertGuest();
    $this->assertDatabaseCount('azure_identities', 0);
});

it('links an existing user when explicitly enabled and logs in', function () {
    config(['filament-azure-auth.link_existing_users' => true]);
    $user = User::create(['name' => 'Existing', 'email' => 'person@example.org', 'password' => 'unchanged']);
    $this->fakeMicrosoft();
    $this->completeAzureCallback()->assertRedirect('/admin');
    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseHas('azure_identities', ['tenant_id' => '11111111-1111-4111-8111-111111111111', 'object_id' => 'object-123', 'user_id' => (string) $user->id]);
    expect($user->fresh()->password)->toBe('unchanged');
});

it('provisions an account when enabled', function () {
    config(['filament-azure-auth.auto_provision' => true]);
    $this->fakeMicrosoft();
    $this->completeAzureCallback()->assertRedirect('/admin');
    $user = User::firstOrFail();
    $this->assertAuthenticatedAs($user);
    expect($user->email)->toBe('person@example.org');
    expect(Hash::isHashed($user->password))->toBeTrue();
    $this->assertDatabaseCount('azure_identities', 1);
});

it('refuses panel access before authenticating', function () {
    config(['filament-azure-auth.link_existing_users' => true]);
    User::create(['name' => 'Denied', 'email' => 'person@example.org', 'password' => 'unused', 'allowed' => false]);
    $this->fakeMicrosoft();
    $this->completeAzureCallback()->assertRedirect('/admin/login')
        ->assertSessionHas('filament-socialite-login-error', 'Your account does not have access to this panel. Contact your administrator.');
    $this->assertGuest();
    $this->assertDatabaseCount('azure_identities', 0);
});

it('does not expose Microsoft error details', function () {
    $this->fakeMicrosoft(status: 400);
    $this->completeAzureCallback()->assertRedirect('/admin/login')
        ->assertSessionHas('filament-socialite-login-error', 'Microsoft sign-in is temporarily unavailable. Please try again.');
    $this->assertGuest();
});

it('handles cancelled consent without contacting Microsoft', function () {
    $handler = $this->fakeMicrosoft();
    $this->get('/admin/oauth/callback/filament-azure?error=access_denied')->assertRedirect('/admin/login')
        ->assertSessionHas('filament-socialite-login-error', 'Microsoft sign-in was cancelled or denied. Please try again.');
    expect($handler->count())->toBe(2);
    $this->assertGuest();
});

it('uses the stable Microsoft identity after the email changes', function () {
    $user = User::create(['name' => 'Existing', 'email' => 'old@example.org', 'password' => 'unchanged']);
    AzureIdentity::create([
        'tenant_id' => config('filament-azure-auth.tenant'), 'object_id' => 'object-123', 'user_type' => User::class, 'user_id' => (string) $user->id,
    ]);
    $this->fakeMicrosoft('new@example.org');
    $this->completeAzureCallback()->assertRedirect('/admin');
    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('azure_identities', 1);
});

it('does not reuse an identity from a different tenant', function () {
    $user = User::create(['name' => 'Existing', 'email' => 'person@example.org', 'password' => 'unused']);
    AzureIdentity::create([
        'tenant_id' => '22222222-2222-4222-8222-222222222222', 'object_id' => 'object-123', 'user_type' => User::class, 'user_id' => (string) $user->id,
    ]);
    $this->fakeMicrosoft();
    $this->completeAzureCallback()->assertRedirect('/admin/login');
    $this->assertGuest();
});

it('does not create a duplicate local account when provisioning meets an unlinked email', function () {
    config(['filament-azure-auth.auto_provision' => true]);
    User::create(['name' => 'Existing', 'email' => 'person@example.org', 'password' => 'unused']);
    $this->fakeMicrosoft();
    $this->completeAzureCallback()->assertRedirect('/admin/login')
        ->assertSessionHas('filament-socialite-login-error', 'This account has not been linked to Microsoft. Contact your administrator.');
    $this->assertGuest();
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('azure_identities', 0);
});

it('handles a deleted linked user without authenticating', function () {
    AzureIdentity::create([
        'tenant_id' => config('filament-azure-auth.tenant'), 'object_id' => 'object-123', 'user_type' => User::class, 'user_id' => '999',
    ]);
    $this->fakeMicrosoft();
    $this->completeAzureCallback()->assertRedirect('/admin/login')
        ->assertSessionHas('filament-socialite-login-error', 'Your linked account is unavailable. Contact your administrator.');
    $this->assertGuest();
});

it('does not bypass enabled application MFA', function () {
    if (! interface_exists(MultiFactorAuthenticationProvider::class)) {
        $this->markTestSkipped('Filament 3 has no native MFA provider API.');
    }

    config(['filament-azure-auth.link_existing_users' => true]);
    User::create(['name' => 'Existing', 'email' => 'person@example.org', 'password' => 'unused']);
    $mfa = Mockery::mock(MultiFactorAuthenticationProvider::class);
    $mfa->shouldReceive('getId')->andReturn('example');
    $mfa->shouldReceive('isEnabled')->once()->andReturn(true);
    Filament::getPanel('admin')->multiFactorAuthentication([$mfa]);
    $this->fakeMicrosoft();
    $this->completeAzureCallback()->assertRedirect('/admin/login')
        ->assertSessionHas('filament-socialite-login-error', 'Use the standard sign-in form to complete your application two-factor authentication.');
    $this->assertGuest();
    $this->assertDatabaseCount('azure_identities', 0);
});

it('renders the Microsoft button on the host login page', function () {
    $this->get('/admin/login')->assertOk()->assertSee('Sign in with Microsoft')
        ->assertSee('/admin/oauth/filament-azure');
});

it('rejects users outside the configured email domain', function () {
    config(['filament-azure-auth.auto_provision' => true]);
    Filament::getPanel('admin')->getPlugin('filament-socialite')->domainAllowList(['iom.int']);
    $this->fakeMicrosoft('person@example.org');
    $this->completeAzureCallback()->assertRedirect('/admin/login');
    $this->assertGuest();
    $this->assertDatabaseCount('users', 0);
});

it('rejects missing Microsoft email without creating an account', function () {
    config(['filament-azure-auth.auto_provision' => true]);
    $this->fakeMicrosoft('');
    $this->completeAzureCallback()->assertRedirect('/admin/login');
    $this->assertGuest();
    $this->assertDatabaseCount('users', 0);
});

it('rolls back provisioning when the configured role cannot be assigned', function () {
    config(['filament-azure-auth.auto_provision' => true, 'filament-azure-auth.default_role' => 'panel_user']);
    $this->fakeMicrosoft();
    $this->completeAzureCallback()->assertServerError();
    $this->assertGuest();
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('azure_identities', 0);
});

it('publishes installation files without overwriting existing configuration', function () {
    $configPath = config_path('filament-azure-auth.php');
    $migrationPattern = database_path('migrations/*_create_azure_identities_table.php');
    try {
        $this->artisan('azure-auth:install')->assertSuccessful();
        expect(is_file($configPath))->toBeTrue();
        expect(glob($migrationPattern))->toHaveCount(1);
        file_put_contents($configPath, '<?php return ["custom" => true];');
        $this->artisan('azure-auth:install')->assertSuccessful();
        expect(file_get_contents($configPath))->toBe('<?php return ["custom" => true];');
        expect(glob($migrationPattern))->toHaveCount(1);
    } finally {
        @unlink($configPath);
        foreach (glob($migrationPattern) as $migration) {
            unlink($migration);
        }
    }
});

it('does not reuse a cached OAuth user for a later callback with invalid state', function () {
    config(['filament-azure-auth.auto_provision' => true]);
    $handler = $this->fakeMicrosoft();
    $this->completeAzureCallback()->assertRedirect('/admin');
    auth()->logout();
    $this->completeAzureCallback('invalid-state')->assertRedirect('/admin/login');
    $this->assertGuest();
    expect($handler->count())->toBe(0);
    $this->assertDatabaseCount('users', 1);
});

it('rate limits repeated authentication requests', function () {
    config(['filament-azure-auth.tenant' => null]);
    for ($attempt = 0; $attempt < 60; $attempt++) {
        $this->get('/admin/oauth/filament-azure')->assertRedirect('/admin/login');
    }
    $this->get('/admin/oauth/filament-azure')->assertTooManyRequests();
});
