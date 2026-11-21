<?php

use App\Gameplay\AdventureApiServiceProvider;
use App\Gameplay\AdventureFilamentServiceProvider;
use App\Gameplay\AdventureLivewireServiceProvider;
use App\Gameplay\AdventureServiceProvider;
use App\Gameplay\ArenaApiServiceProvider;
use App\Gameplay\ArenaFilamentServiceProvider;
use App\Gameplay\ArenaLivewireServiceProvider;
use App\Gameplay\ArenaServiceProvider;
use App\Gameplay\CommunityApiServiceProvider;
use App\Gameplay\CommunityFilamentServiceProvider;
use App\Gameplay\CommunityLivewireServiceProvider;
use App\Gameplay\CommunityServiceProvider;
use App\Gameplay\InventoryApiServiceProvider;
use App\Gameplay\InventoryFilamentServiceProvider;
use App\Gameplay\InventoryLivewireServiceProvider;
use App\Gameplay\InventoryServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\AppPanelProvider;
use Liberu\Foundation\ModuleManager\ModuleManagerServiceProvider;

return [
    ModuleManagerServiceProvider::class,
    AppServiceProvider::class,
    AdventureServiceProvider::class,
    AdventureApiServiceProvider::class,
    AdventureFilamentServiceProvider::class,
    AdventureLivewireServiceProvider::class,
    ArenaServiceProvider::class,
    ArenaApiServiceProvider::class,
    ArenaFilamentServiceProvider::class,
    ArenaLivewireServiceProvider::class,
    CommunityServiceProvider::class,
    CommunityApiServiceProvider::class,
    CommunityFilamentServiceProvider::class,
    CommunityLivewireServiceProvider::class,
    InventoryServiceProvider::class,
    InventoryApiServiceProvider::class,
    InventoryFilamentServiceProvider::class,
    InventoryLivewireServiceProvider::class,
    AdminPanelProvider::class,
    AppPanelProvider::class,
];
