<?php

namespace EdrisaTuray\FilamentAzureAuth\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'azure-auth:install';

    protected $description = 'Publish Azure authentication configuration and migration, and show setup instructions';

    public function handle(): int
    {
        foreach (['filament-azure-auth-config', 'filament-azure-auth-migrations'] as $tag) {
            if ($this->call('vendor:publish', ['--tag' => $tag]) !== self::SUCCESS) {
                return self::FAILURE;
            }
        }

        $this->components->info('Azure authentication files published.');
        $this->line('1. Run php artisan migrate.');
        $this->line('2. Add ->plugin(\\EdrisaTuray\\FilamentAzureAuth\\AzureAuthPlugin::make()) to your panel.');
        $this->line('3. Set AZURE_TENANT_ID (tenant UUID), AZURE_CLIENT_ID, AZURE_CLIENT_SECRET, AZURE_REDIRECT_URI.');
        $this->line('4. Register a Web redirect URI in Entra ID matching /{panel-path}/oauth/callback/filament-azure.');
        $this->line('5. Enable AZURE_LINK_EXISTING_USERS only if tenant identities may claim matching local email accounts.');
        $this->line('6. Optionally enable AZURE_AUTO_PROVISION and set AZURE_DEFAULT_ROLE to an existing role.');
        $this->line('7. Run php artisan filament:assets and rebuild cached configuration after changing environment settings.');
        $this->line('Use a single-tenant Entra app registration with Microsoft Graph delegated User.Read permission.');
        $this->line('Users must implement FilamentUser. Existing application MFA remains required through standard sign-in.');
        $this->line('Logout uses the host application logout. It does not sign users out of Microsoft globally.');

        return self::SUCCESS;
    }
}
