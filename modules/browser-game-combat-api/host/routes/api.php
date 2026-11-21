<?php

use App\Http\Controllers\Api\CombatController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', 'auth:sanctum'])->prefix('api')->group(function (): void {
    Route::prefix('combat')->group(function (): void {
        Route::post('/pve', [CombatController::class, 'pve']);
        Route::post('/pvp', [CombatController::class, 'pvp']);
        Route::post('/heal', [CombatController::class, 'heal']);
    });
});
