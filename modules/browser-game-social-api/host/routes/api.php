<?php

use App\Http\Controllers\Api\DailyRewardController;
use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', 'auth:sanctum'])->prefix('api')->group(function (): void {
    Route::prefix('players/{player}')->scopeBindings()->group(function (): void {
        Route::get('/daily-reward/status', [DailyRewardController::class, 'status']);
        Route::post('/daily-reward/claim', [DailyRewardController::class, 'claim']);
    });

    Route::prefix('notifications')->group(function (): void {
        Route::get('/', [NotificationController::class, 'index'])->name('api.notifications.index');
        Route::get('/unread', [NotificationController::class, 'unread'])->name('api.notifications.unread');
        Route::get('/count', [NotificationController::class, 'count'])->name('api.notifications.count');
        Route::post('/{id}/read', [NotificationController::class, 'markAsRead'])->name('api.notifications.mark-as-read');
        Route::post('/read-all', [NotificationController::class, 'markAllAsRead'])->name('api.notifications.mark-all-as-read');
    });
});
