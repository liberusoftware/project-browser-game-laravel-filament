<?php

declare(strict_types=1);

namespace App\Gameplay;

use Illuminate\Support\ServiceProvider;

final class ArenaFilamentServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make('view')->addLocation(__DIR__.'/../resources/views');
    }
}
