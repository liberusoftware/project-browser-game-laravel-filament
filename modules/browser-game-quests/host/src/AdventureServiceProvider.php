<?php

declare(strict_types=1);

namespace App\Gameplay;

use Illuminate\Support\ServiceProvider;

final class AdventureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/starter-adventure.php', 'starter-adventure');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
