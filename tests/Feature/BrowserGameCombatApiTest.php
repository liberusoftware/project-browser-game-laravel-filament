<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Liberu\BrowserGame\Combat\CombatServiceProvider;
use Liberu\BrowserGame\Combat\Support\CombatManager;
use Liberu\BrowserGame\CombatApi\CombatApiServiceProvider;

it('exposes combat definitions, simulation, and persisted actions through the combat API', function (): void {
    $this->app->register(CombatServiceProvider::class);
    $this->app->register(CombatApiServiceProvider::class);
    $this->artisan('migrate')->assertSuccessful();

    $player = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $player->id]);
    $otherTeam = Team::factory()->create();
    $player->forceFill(['current_team_id' => $team->getKey()])->save();
    Sanctum::actingAs($player);

    $base = '/api/v1/browser-game/combat';
    $this->postJson($base.'/definitions', [
        'kind' => 'ability',
        'slug' => 'power-strike-api',
        'name' => 'Power Strike',
        'effects' => ['damage' => 30],
        'data' => ['power' => 30],
    ])->assertCreated()->assertJsonPath('data.attributes.effects.damage', 30);

    $state = ['health' => ['actor' => 100, 'opponent' => 40]];
    $this->postJson($base.'/simulate', [
        'opponent_id' => 'training-target',
        'actions' => [['action' => 'attack', 'value' => 40]],
        'state' => $state,
    ])->assertOk()->assertJsonPath('data.opponent_id', 'training-target');

    $battle = $this->postJson($base, ['opponent_id' => 'training-target', 'state' => $state], ['Idempotency-Key' => 'battle-api'])
        ->assertCreated()->assertJsonPath('data.attributes.status', 'active')->json('data.id');
    $this->postJson($base, ['opponent_id' => 'training-target', 'state' => $state], ['Idempotency-Key' => 'battle-api'])
        ->assertCreated()->assertJsonPath('data.id', $battle);
    $hidden = app(CombatManager::class)->start('other-player', 'other-target', teamId: (string) $otherTeam->getKey());
    $this->getJson($base.'?page_size=1')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $battle);
    $this->getJson($base.'/'.$battle)->assertOk()->assertJsonPath('data.attributes.state.health.opponent', 40);
    $this->getJson($base.'/'.$hidden->getKey())->assertNotFound();
    $this->postJson($base.'/'.$battle.'/actions', ['action' => 'power-strike-api'])
        ->assertOk()->assertJsonPath('data.attributes.state.health.opponent', 10);
});
