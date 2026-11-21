<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Liberu\BrowserGame\Crafting\CraftingServiceProvider;
use Liberu\BrowserGame\Crafting\Support\CraftingManager;
use Liberu\BrowserGame\CraftingApi\CraftingApiServiceProvider;

beforeEach(function (): void {
    $this->app->register(CraftingServiceProvider::class);
    $this->app->register(CraftingApiServiceProvider::class);
    $this->artisan('migrate')->assertSuccessful();
});

it('lists visible crafting recipes and scopes the recipe detail to the current team', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $otherTeam = Team::factory()->create();
    $user->forceFill(['current_team_id' => $team->getKey()])->save();
    Sanctum::actingAs($user);

    $manager = app(CraftingManager::class);
    $visible = $manager->define('Visible recipe', teamId: (string) $team->getKey());
    $hidden = $manager->define('Hidden recipe', teamId: (string) $otherTeam->getKey());

    $this->getJson('/api/v1/browser-game/crafting?page_size=1')
        ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('per_page', 1)
        ->assertJsonPath('data.0.id', $visible->getKey());
    $this->getJson('/api/v1/browser-game/crafting/'.$visible->getKey())
        ->assertOk()->assertJsonPath('data.attributes.name', 'Visible recipe');
    $this->getJson('/api/v1/browser-game/crafting/'.$hidden->getKey())->assertNotFound();

    $user->setRelation('currentTeam', null);
    $this->getJson('/api/v1/browser-game/crafting/'.$visible->getKey())->assertNotFound();
});

it('runs crafting queues through completion, salvage, and cancellation for their owner', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $user->forceFill(['current_team_id' => $team->getKey()])->save();
    Sanctum::actingAs($user);

    $manager = app(CraftingManager::class);
    $recipe = $manager->define('Sword', [
        'materials' => ['iron' => 1],
        'outputs' => ['sword' => 1],
        'salvage' => ['iron' => 1],
        'crafting_time_seconds' => 0,
        'success_rate' => 100,
    ], teamId: (string) $team->getKey());
    $manager->grantResource((string) $user->getKey(), 'iron', 2, teamId: (string) $team->getKey());

    $base = '/api/v1/browser-game/crafting';
    $this->postJson($base.'/'.$recipe->getKey().'/discover')
        ->assertCreated()->assertJsonPath('data.attributes.recipe_id', $recipe->getKey());
    $payload = ['recipe_id' => $recipe->getKey(), 'quantity' => 1];
    $queueId = $this->postJson($base.'/queue', $payload, ['Idempotency-Key' => ' sword-1 '])
        ->assertAccepted()->assertJsonPath('data.attributes.status', 'queued')->json('data.id');
    $this->postJson($base.'/queue', $payload, ['Idempotency-Key' => 'sword-1'])
        ->assertAccepted()->assertJsonPath('data.id', $queueId);
    $this->getJson($base.'/queue')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson($base.'/queue/'.$queueId.'/complete')
        ->assertOk()->assertJsonPath('data.attributes.status', 'completed');
    $this->getJson($base.'/professions')
        ->assertOk()->assertJsonPath('data.0.attributes.experience', 1);
    $this->postJson($base.'/queue/'.$queueId.'/salvage')
        ->assertOk()->assertJsonPath('data.attributes.status', 'salvaged');

    $cancelId = $this->postJson($base.'/queue', $payload)->assertAccepted()->json('data.id');
    $this->postJson($base.'/queue/'.$cancelId.'/cancel')
        ->assertOk()->assertJsonPath('data.attributes.status', 'cancelled');
    $this->getJson($base.'/resources')->assertOk()->assertJsonPath('data.0.attributes.quantity', 2);
});
