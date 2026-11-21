<?php

declare(strict_types=1);

namespace App\Gameplay;

use Filament\Contracts\Plugin;
use Filament\Panel;

final class CommunityPanelPlugin implements Plugin
{
    public function getId(): string
    {
        return 'social-filament-host';
    }

    public function register(Panel $panel): void
    {
        $panelNamespace = ucfirst($panel->getId());
        $directory = __DIR__.'/Filament/'.$panelNamespace;
        $namespace = 'App\\Filament\\'.$panelNamespace;

        $panel
            ->discoverResources(in: $directory.'/Resources', for: $namespace.'\\Resources')
            ->discoverPages(in: $directory.'/Pages', for: $namespace.'\\Pages')
            ->discoverWidgets(in: $directory.'/Widgets', for: $namespace.'\\Widgets');
    }

    public function boot(Panel $panel): void {}
}
