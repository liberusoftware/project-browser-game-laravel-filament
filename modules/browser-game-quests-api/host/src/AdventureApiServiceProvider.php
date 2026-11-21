<?php

declare(strict_types=1);

namespace App\Gameplay;

use Illuminate\Support\ServiceProvider;

final class AdventureApiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
    }
}
