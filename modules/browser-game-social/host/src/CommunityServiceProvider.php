<?php

declare(strict_types=1);

namespace App\Gameplay;

use App\Console\Commands\UpdatePlayerRankings;
use Illuminate\Support\ServiceProvider;

final class CommunityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->commands([UpdatePlayerRankings::class]);
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
