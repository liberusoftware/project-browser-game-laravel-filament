<?php

use App\Models\Achievement;
use App\Models\Guild;
use App\Models\Player;
use App\Models\Quest;
use App\Models\Recipe;
use App\Models\Skill;
use Database\Seeders\GameContentSeeder;
use Database\Seeders\GameSeeder;
use Database\Seeders\PlayerStatisticsSeeder;
use Database\Seeders\PlayerTrackingDemoSeeder;
use Database\Seeders\QuestSeeder;

it('seeds connected game content and derives statistics from the demo players', function (): void {
    $this->seed([GameSeeder::class, QuestSeeder::class, GameContentSeeder::class, PlayerStatisticsSeeder::class]);

    $player = Player::where('username', 'player1')->firstOrFail();
    $guild = Guild::where('name', 'Warriors of Light')->firstOrFail();
    $quest = Quest::where('name', "Beginner's Trial")->firstOrFail();
    $recipe = Recipe::with('materials.item')->where('name', 'Forge Iron Sword')->firstOrFail();

    expect($guild->leaders()->whereKey($player->id)->exists())->toBeTrue()
        ->and($player->resources()->pluck('resource_type')->all())->toEqualCanonicalizing(['gold', 'wood', 'stone'])
        ->and($player->statistics->total_quests_completed)->toBe($player->completedQuests()->count())
        ->and($player->statistics->total_items_collected)->toBe((int) $player->items()->sum('quantity'))
        ->and($quest->itemReward->name)->toBe('Iron Sword')
        ->and($recipe->resultItem->is($quest->itemReward))->toBeTrue()
        ->and($recipe->materials->pluck('quantity', 'item.name')->all())->toEqual(['Iron Ore' => 5, 'Wood' => 2])
        ->and(Skill::where('name', 'Power Strike')->exists())->toBeTrue();

    $statisticsId = $player->statistics->id;
    $this->seed(PlayerStatisticsSeeder::class);

    expect($player->fresh()->statistics->id)->toBe($statisticsId);
});

it('seeds player tracking at different progression levels', function (): void {
    $this->seed(PlayerTrackingDemoSeeder::class);

    $novice = Player::where('username', 'novice_player')->firstOrFail();
    $master = Player::where('username', 'master_player')->firstOrFail();

    expect(Achievement::count())->toBeGreaterThan(0)
        ->and($novice->completedQuests()->count())->toBe(1)
        ->and($master->statistics->total_experience_earned)->toBe(125000)
        ->and($master->statistics->achievements_unlocked)->toBeGreaterThan($novice->statistics->achievements_unlocked);
});
