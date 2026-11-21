<?php

use App\Models\Player;
use Database\Seeders\PlayerRankingSeeder;

it('seeds sample players in score order on the leaderboard', function (): void {
    $this->seed(PlayerRankingSeeder::class);

    $players = Player::orderBy('rank')->get();

    expect($players)->toHaveCount(10)
        ->and($players->first()->username)->toBe('DragonSlayer')
        ->and($players->first()->rank)->toBe(1)
        ->and($players->last()->username)->toBe('Apprentice')
        ->and($players->last()->rank)->toBe(10);
});
