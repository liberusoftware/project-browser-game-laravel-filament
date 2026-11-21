<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Liberu\BrowserGame\Characters\CharactersServiceProvider;
use Liberu\BrowserGame\CharactersApi\CharactersApiServiceProvider;

it('creates, progresses, and updates a character in its owner and team context', function (): void {
    $this->app->register(CharactersServiceProvider::class);
    $this->app->register(CharactersApiServiceProvider::class);
    $this->artisan('migrate')->assertSuccessful();

    $player = User::factory()->create();
    $teammate = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $player->id]);
    foreach ([$player, $teammate] as $user) {
        $user->forceFill(['current_team_id' => $team->getKey()])->save();
    }
    Sanctum::actingAs($player);

    $base = '/api/v1/browser-game/characters';
    $profile = ['name' => 'Lyra', 'race' => 'human', 'class' => 'warrior'];
    $character = $this->postJson($base, $profile)
        ->assertCreated()->assertJsonPath('data.attributes.level', 1)->json('data.id');
    $url = $base.'/'.$character;
    $this->getJson($base.'?page_size=1')->assertOk()->assertJsonPath('data.0.id', $character);
    $this->getJson($url)->assertOk()->assertJsonPath('data.attributes.name', 'Lyra');
    $this->patchJson($url, [...$profile, 'background' => 'Sailor'])
        ->assertOk()->assertJsonPath('data.attributes.background', 'Sailor');

    $this->postJson($url.'/experience', ['amount' => 100], ['Idempotency-Key' => 'first-quest'])
        ->assertOk()->assertJsonPath('data.attributes.level', 2);
    $this->postJson($url.'/experience', ['amount' => 100], ['Idempotency-Key' => 'first-quest'])
        ->assertOk()->assertJsonPath('data.attributes.experience', 100);
    $this->postJson($url.'/stats', ['statistics' => ['strength' => 2]])
        ->assertOk()->assertJsonPath('data.attributes.stat_points', 3);
    $this->postJson($url.'/skills', ['skills' => ['sailing' => 2]])
        ->assertOk()->assertJsonPath('data.attributes.skills.sailing', 2);
    $this->postJson($url.'/respec', ['skills' => ['navigation' => 1]])
        ->assertOk()->assertJsonPath('data.attributes.skills.navigation', 1)
        ->assertJsonPath('data.attributes.respec_count', 1);
    $this->postJson($url.'/vitals', ['health' => 90, 'mana' => 45])
        ->assertOk()->assertJsonPath('data.attributes.health', 90)->assertJsonPath('data.attributes.mana', 45);

    Sanctum::actingAs($teammate);
    $this->getJson($url)->assertNotFound();
    $this->getJson($base)->assertOk()->assertJsonCount(0, 'data');
    Sanctum::actingAs($player);
    $player->setRelation('currentTeam', Team::factory()->create());
    $this->getJson($url)->assertNotFound();
});
