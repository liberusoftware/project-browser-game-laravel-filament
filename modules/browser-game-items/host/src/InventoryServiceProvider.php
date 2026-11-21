<?php

declare(strict_types=1);

namespace App\Gameplay;

use App\Console\Commands\BenchmarkInventory;
use Illuminate\Support\ServiceProvider;

final class InventoryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->commands([BenchmarkInventory::class]);
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
