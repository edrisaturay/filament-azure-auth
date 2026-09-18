<?php

namespace EdrisaTuray\FilamentAzureAuth\Tests\Fixtures;

use EdrisaTuray\FilamentAzureAuth\AzureAuthPlugin;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->id('admin')->path('admin')->default()->login()
            ->middleware(['web'])
            ->pages([Dashboard::class])
            ->plugin(AzureAuthPlugin::make());
    }
}
