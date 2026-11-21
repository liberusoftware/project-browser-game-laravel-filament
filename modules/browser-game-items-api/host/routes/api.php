<?php

use App\Http\Controllers\Api\CraftingController;
use App\Http\Controllers\Api\MarketplaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', 'auth:sanctum'])->prefix('api')->group(function (): void {
    Route::prefix('players/{player}')->scopeBindings()->group(function (): void {
        Route::get('/recipes', [CraftingController::class, 'playerRecipes']);
    });

    Route::prefix('crafting')->group(function (): void {
        Route::post('/recipes/{recipe}/craft', [CraftingController::class, 'craft']);
        Route::post('/recipes/{recipe}/learn', [CraftingController::class, 'learn']);
    });

    Route::prefix('marketplace')->group(function (): void {
        Route::get('/', [MarketplaceController::class, 'index']);
        Route::post('/', [MarketplaceController::class, 'store']);
        Route::post('/{listing}/purchase', [MarketplaceController::class, 'purchase']);
        Route::delete('/{listing}/cancel', [MarketplaceController::class, 'cancel']);
    });
});
