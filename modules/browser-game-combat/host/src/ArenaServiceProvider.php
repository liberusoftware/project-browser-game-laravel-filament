<?php

declare(strict_types=1);

namespace App\Gameplay;

use Illuminate\Support\ServiceProvider;

final class ArenaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/arena.php', 'arena');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
