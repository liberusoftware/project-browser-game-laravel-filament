<?php

use App\Http\Controllers\Api\PlayerAchievementsController;
use App\Http\Controllers\Api\PlayerStatisticsController;
use App\Http\Controllers\QuestController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', 'auth:sanctum'])->prefix('api')->group(function (): void {
    Route::prefix('players/{player}')->scopeBindings()->group(function (): void {
        Route::get('/statistics', [PlayerStatisticsController::class, 'show']);
        Route::get('/progression', [PlayerStatisticsController::class, 'progression']);
        Route::get('/achievements', [PlayerAchievementsController::class, 'index']);
        Route::get('/achievements/unlocked', [PlayerAchievementsController::class, 'unlocked']);
    });

    Route::get('/achievements', [PlayerAchievementsController::class, 'available']);

    Route::prefix('quests')->group(function (): void {
        Route::get('/available', [QuestController::class, 'available']);
        Route::get('/active', [QuestController::class, 'active']);
        Route::get('/completed', [QuestController::class, 'completed']);
        Route::post('/{quest}/accept', [QuestController::class, 'accept']);
        Route::post('/{quest}/complete', [QuestController::class, 'complete']);
        Route::delete('/{quest}/abandon', [QuestController::class, 'abandon']);
    });
});
