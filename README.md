# Filament Azure Auth

Microsoft Entra ID (formerly Azure Active Directory) sign-in for Filament panels.

`edrisaturay/filament-azure-auth` packages the Microsoft login integration used as the starting point in DNA Collection into a reusable library. It uses Laravel Socialite, the Microsoft Azure provider, and Filament Socialite. It does not depend on the Filament starter package.

## Status and compatibility

This is a local, unpublished package. The installation instructions below use a Composer path repository. A plain `composer require edrisaturay/filament-azure-auth` will not work until the package is available from a configured Composer repository.

| Component | Requirement | Verified version |
| --- | --- | --- |
| PHP | `^8.3`, subject to the selected Laravel version | Use the PHP version required by your application |
| Laravel | `^12.0` or `^13.0` | 13.32.0 |
| Filament | `^5.0` | 5.8.2 |
| Filament Socialite | `^3.2.1` | 3.2.1 |
| Microsoft Azure provider | `^5.0` | 5.2.1 |

Laravel 12 is permitted by the dependency constraints but has not been tested in this package's current verification run. Filament 3 and 4 are not supported by this release.

## Features

- Microsoft sign-in button on the existing Filament login page.
- Stateful OAuth redirect and callback handling.
- One configured Entra tenant per application.
- Persistent identity mapping using tenant ID, Microsoft object ID, and local user model.
- Optional first-login account creation and optional linking to existing local accounts.
- Optional default role assignment for newly created accounts.
- Application panel access checks before authentication.
- Friendly messages for cancelled login, invalid state, denied access, and Microsoft HTTP failures.
- Authentication endpoint rate limiting, 60 requests per minute under Laravel's throttle middleware.
- A setup command that publishes configuration and the identity migration.

## Installation

Run these commands from the consuming Laravel application's root.

### 1. Make the package available to Composer

For the current workspace, the package is already at `packages/filament-azure-auth`. In another application, copy the package source into the same relative directory, excluding its `vendor` directory and development artifacts.

Merge this entry into the application's `composer.json`. Preserve any existing repositories:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "packages/filament-azure-auth",
            "options": {
                "symlink": false,
                "versions": {
                    "edrisaturay/filament-azure-auth": "dev-main"
                }
            }
        }
    ]
}
```

Then install:

```bash
composer require edrisaturay/filament-azure-auth:dev-main
```

The explicit version alias lets Composer install the unpublished source without changing the application's global minimum stability. With this path repository arrangement, include the package source in deployment artifacts so Composer can install it on the build server.

### 2. Publish configuration and migrate

```bash
php artisan azure-auth:install --no-interaction
php artisan migrate --no-interaction
php artisan filament:assets --no-interaction
```

The installer publishes:

- `config/filament-azure-auth.php`
- A migration creating `azure_identities`

The installer does not run migrations, modify the panel provider, create roles, edit `.env`, or configure Azure. Re-running it preserves existing published configuration.

Do not publish Filament Socialite's `socialite_users` migration just for this package. This package uses its own identity table.

### 3. Register the panel plugin

In the consuming panel provider, add the plugin while retaining the rest of the existing panel configuration:

```php
use EdrisaTuray\FilamentAzureAuth\AzureAuthPlugin;
use Filament\Panel;

public function panel(Panel $panel): Panel
{
    return $panel
        ->id('admin')
        ->path('admin')
        ->login()
        ->plugin(AzureAuthPlugin::make());
}
```

Keep the panel's standard session and cookie middleware. The package adds its button to the login form's `panels::auth.login.form.after` hook. A custom login view must render that hook.

This plugin uses Filament Socialite's `filament-socialite` plugin ID. Do not register a separate `FilamentSocialitePlugin` instance in the same panel, because one registration would replace the other.

### 4. Configure the application

```dotenv
AZURE_TENANT_ID=your-directory-tenant-uuid
AZURE_CLIENT_ID=your-application-client-id
AZURE_CLIENT_SECRET=your-client-secret-value
AZURE_REDIRECT_URI=https://your-app.example/admin/oauth/callback/filament-azure

AZURE_LINK_EXISTING_USERS=false
AZURE_AUTO_PROVISION=false
AZURE_DEFAULT_ROLE=null
```

The hostname above is an example. Use your application's externally accessible hostname and actual panel path. Set secrets through your deployment environment or secret store, not committed source files.

Choose an account policy from the next section before expecting a first login to succeed. With both account options disabled, only identities already linked in `azure_identities` can sign in.

## Azure / Microsoft Entra setup

1. In the Microsoft Entra admin center, open **App registrations** and create or select the application registration for this environment.
2. Choose **Accounts in this organizational directory only**. Copy the **Directory (tenant) ID** and **Application (client) ID** into the corresponding environment settings.
3. Add a **Web** platform redirect URI. For a panel at `/admin`, the callback path is `/admin/oauth/callback/filament-azure`. Its full URI must match `AZURE_REDIRECT_URI`.
4. Create a client secret under **Certificates & secrets**. Use its **Value** as `AZURE_CLIENT_SECRET`, not its secret ID. Track its expiry and rotate it before expiration.
5. Configure Microsoft Graph **delegated** `User.Read` permission. Follow the organization's consent policy, including administrator consent when required.
6. Apply the organization's user assignment and Conditional Access requirements to the enterprise application.

See Microsoft's [application registration guide](https://learn.microsoft.com/en-us/graph/auth-register-app-v2) and [redirect URI requirements](https://learn.microsoft.com/en-us/entra/identity-platform/reply-url).

Use HTTPS for deployed environments. This implementation uses a confidential web application flow with a client secret. It does not require enabling implicit token grants or configuring a single-page application platform.

The tenant setting must be a UUID. Values such as `common`, `organizations`, or a tenant domain are rejected by the package's configuration check.

## Account policies

### Existing local accounts only

```dotenv
AZURE_LINK_EXISTING_USERS=true
AZURE_AUTO_PROVISION=false
```

The first Microsoft login may link an existing local user whose email exactly matches the provider's email value. Unknown users are rejected. Existing passwords and roles are preserved.

Enable this only when identities from the configured Entra tenant are allowed to claim matching local accounts. The provider currently maps its email value from Microsoft's `userPrincipalName`, which may differ from the user's mailbox address.

### Automatically create users

```dotenv
AZURE_AUTO_PROVISION=true
AZURE_LINK_EXISTING_USERS=false
```

Unknown identities can create local accounts. An existing matching email is refused rather than silently linked or duplicated. Enable `AZURE_LINK_EXISTING_USERS` as well if the application deliberately allows both behaviors.

The default creator writes `name`, `email`, and a randomly generated, hashed password. It does not mark the email as verified, synchronize profile changes, or email the generated password.

The user table must accept those attributes without other mandatory values. Applications with additional required fields should provide a custom creator.

### Default role

```dotenv
AZURE_DEFAULT_ROLE=panel_user
```

Role assignment is optional. If configured, the role must already exist and the user model must provide `assignRole()`, for example through Spatie Permission. Use the role's correct guard.

The default role applies only to newly created users, not existing users or repeat logins. The package does not install a role-management library or create the role. An invalid role configuration can fail provisioning; the database transaction rolls back the new account.

### Panel authorization

The local user must implement `Filament\Models\Contracts\FilamentUser`. Its existing `canAccessPanel(Panel $panel): bool` method decides whether the account may enter the panel.

Microsoft authentication does not grant application privileges. An automatically created user must satisfy the same panel access policy as any other user. The default creator checks that policy inside the provisioning transaction.

## Configuration reference

| Environment variable | Default | Purpose |
| --- | --- | --- |
| `AZURE_TENANT_ID` | unset | Required single-tenant directory UUID |
| `AZURE_CLIENT_ID` | unset | Required Entra application client ID |
| `AZURE_CLIENT_SECRET` | unset | Required client secret value |
| `AZURE_REDIRECT_URI` | unset | Required absolute Web callback URI |
| `AZURE_LINK_EXISTING_USERS` | `false` | Allow first-login linking by matching email |
| `AZURE_AUTO_PROVISION` | `false` | Allow creation of unknown users |
| `AZURE_DEFAULT_ROLE` | unset | Existing role to assign to newly created users |

Environment variables are read through the published configuration file. Rebuild cached configuration after changing these values:

```bash
php artisan config:cache --no-interaction
```

Restart long-running application workers after deployment or configuration changes where applicable.

## Customization

### Limit email domains

```php
AzureAuthPlugin::make()
    ->domainAllowList(['iom.int']);
```

Use lowercase domains. Domain restrictions supplement the configured tenant and local authorization policy. They do not replace either.

### Customize account creation

```php
use App\Models\User;
use EdrisaTuray\FilamentAzureAuth\AzureAuthPlugin;
use EdrisaTuray\FilamentAzureAuth\EnsurePanelAccess;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as MicrosoftUser;

AzureAuthPlugin::make()
    ->createUserUsing(function (MicrosoftUser $oauthUser, AzureAuthPlugin $plugin): User {
        $user = new User;
        $user->forceFill([
            'name' => $oauthUser->getName() ?: $oauthUser->getEmail(),
            'email' => $oauthUser->getEmail(),
            'password' => Hash::make(Str::random(64)),
            // Add the application's required attributes here.
        ])->save();

        $user->refresh();
        app(EnsurePanelAccess::class)->handle($user, $plugin->getPanel());

        return $user;
    });
```

Keep `AZURE_AUTO_PROVISION=true` to enable creation. The custom callback replaces the default creator, including its collision handling and default role assignment. Implement those policies in the callback when needed. The surrounding Socialite provisioning transaction handles database rollback on failure.

`resolveUserUsing()` and `authorizeUserUsing()` are also inherited extension points. Supplying them replaces the corresponding defaults. A custom authorization callback must preserve the required identity and email checks and any domain restrictions.

## Identity storage and repeat logins

`azure_identities` stores the Entra tenant ID, Microsoft object ID, local user model class, and local user identifier. Its unique key covers tenant, object, and model. The local identifier is stored as a string to accommodate integer or UUID identifiers.

Once linked, login resolves the stable Microsoft identity rather than matching email again. A changed Microsoft email does not automatically change the local user's email. The package does not persist OAuth access or refresh tokens.

The identity table has no foreign key to a particular user table because applications can use different user models. Applications own cleanup when deleting users. A stale link to a deleted user is refused with an account-unavailable message. Renaming a user model also requires updating the stored `user_type` values through an application migration.

Existing DNA `socialite_users` rows are not imported automatically. Plan an explicit migration or an approved linking policy when replacing an existing authentication integration.

## MFA, logout, and multiple panels

- **Microsoft MFA:** Entra controls its own MFA and Conditional Access challenges during Microsoft sign-in.
- **Filament MFA:** If the local user has an enabled provider in the panel's native Filament MFA configuration, this package refuses Microsoft login and directs the user to standard sign-in. It does not yet continue into Filament's MFA challenge. This check does not claim integration with every third-party MFA plugin.
- **Logout:** The host application's existing logout behavior remains responsible for ending its session. The package does not perform Microsoft global logout or implement Azure App Service Easy Auth logout.
- **Multiple panels:** The plugin resolves the user model from each panel's configured auth guard. Azure credentials and the redirect URI are application-wide. The straightforward documented setup uses one panel. Multiple panels, separate credentials per panel, and cross-domain sessions have not been verified by this package's tests.

Password login remains available. This release does not provide a Microsoft-only login page, automatic Entra group-to-role synchronization, certificate authentication, or multi-tenant Entra authentication.

## Deployment

1. Include the package source in the build when using a path repository.
2. Install application dependencies with your normal production Composer workflow.
3. Supply this environment's Azure configuration and register its exact Web redirect URI.
4. Publish configuration and migrations if not already committed to the consuming application.
5. Run outstanding migrations using the application's deployment procedure.
6. Publish Filament assets and rebuild application caches.
7. Restart long-running processes as required, then test an allowed and a denied account.

This package performs application-level OAuth. Azure App Service Authentication / Easy Auth is a separate mechanism and is not configured by the installer. If the hosting layer protects the application, ensure its behavior permits the intended login and callback flow.

The callback requires the same browser session used for the redirect. Across multiple application instances, use compatible shared session storage and the same application encryption key. Ensure proxy and HTTPS configuration produce the correct external URLs and cookies.

## Troubleshooting

| Symptom | What to check |
| --- | --- |
| Microsoft button missing | Plugin registration, `->login()`, custom login render hook, and cached panel configuration |
| Sign-in is not configured | Tenant UUID, client ID, client secret, absolute redirect URI, and cached configuration |
| Redirect URI mismatch / `AADSTS50011` | Exact URI in Entra Web platform settings, including scheme, hostname, path, and case |
| Registration not enabled | Neither account policy permits this first login; choose linking or provisioning deliberately |
| Account has not been linked | A matching local email exists while automatic linking is disabled |
| User has no panel access | `FilamentUser`, `canAccessPanel()`, roles, guard, and application-specific access requirements |
| Standard sign-in requested for MFA | Native Filament MFA is enabled for that user; Microsoft-to-Filament MFA continuation is not implemented |
| Login failed after returning from Microsoft | Session continuity, cookies, encryption key consistency, and starting a fresh login instead of replaying a callback |
| Microsoft temporarily unavailable | Expired secret, network connectivity, permissions, or a Microsoft token/Graph HTTP error |
| HTTP 429 | Authentication endpoint rate limit; users behind a shared network may share a throttle bucket |
| Provisioning server error | Required user columns, custom creator, role existence, and support for `assignRole()` |
| Table not found | Publish and run the `azure_identities` migration |

Microsoft HTTP failure logs contain the exception class, not the upstream response body. Do not add access tokens, authorization codes, or client secrets to application logs when investigating a failure.

## Development and tests

From the package directory:

```bash
composer install
composer test
```

Tests use Orchestra Testbench, an in-memory SQLite database, and fake Microsoft HTTP responses. They do not require Azure credentials or contact Microsoft.

The current verification run passed **22 tests and 230 assertions** on Laravel 13.32.0 and Filament 5.8.2. Coverage includes redirect configuration, OAuth state, account policies, provisioning, panel denial, tenant isolation, stable identity resolution, stale links, native MFA refusal, login-button rendering, installer preservation, Microsoft failures, and throttling.

The live DNA login was observed separately. The new package still needs a real Entra round-trip in a consuming UAT application before production rollout. Test the consuming application's complete suite after integration:

```bash
php artisan test --compact
```

## Distribution

The package has not been published to Packagist or a standalone Git repository. Before distribution, choose a license, create the intended repository, verify supported version combinations, and tag a release. No distribution license has been assigned by this implementation.
