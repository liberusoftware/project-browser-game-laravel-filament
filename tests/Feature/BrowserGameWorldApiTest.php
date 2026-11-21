<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Liberu\BrowserGame\World\WorldServiceProvider;
use Liberu\BrowserGame\WorldApi\WorldApiServiceProvider;

it('exposes the team world catalog and enforces persisted unlocks for travel', function (): void {
    $this->app->register(WorldServiceProvider::class);
    $this->app->register(WorldApiServiceProvider::class);
    $this->artisan('migrate')->assertSuccessful();

    $player = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $player->id]);
    $player->forceFill(['current_team_id' => $team->getKey()])->save();
    Sanctum::actingAs($player);

    $base = '/api/v1/browser-game/world';
    $origin = $this->postJson($base, ['kind' => 'location', 'name' => 'Harbor', 'slug' => 'harbor'])
        ->assertCreated()->json('data.id');
    $destination = $this->postJson($base, [
        'kind' => 'location',
        'name' => 'Observatory',
        'slug' => 'observatory',
        'unlock_key' => 'world.observatory',
    ])->assertCreated()->json('data.id');
    $url = $base.'/'.$destination;
    $this->getJson($base.'?kind=location&page_size=1')->assertOk()
        ->assertJsonPath('total', 2)->assertJsonCount(1, 'data');
    $this->getJson($url)->assertOk()->assertJsonPath('data.attributes.unlock_key', 'world.observatory');
    $this->patchJson($url, [
        'name' => 'Hill Observatory',
        'slug' => 'hill-observatory',
        'status' => 'active',
        'unlock_key' => 'world.observatory',
    ])->assertOk()->assertJsonPath('data.attributes.name', 'Hill Observatory');

    $travel = ['origin_id' => $origin, 'destination_id' => $destination, 'idempotency_key' => 'observatory-trip'];
    $this->postJson($base.'/travel', $travel)->assertUnprocessable();
    $unlock = $this->postJson($url.'/unlock', ['idempotency_key' => 'observatory-key'])
        ->assertCreated()->assertJsonPath('data.attributes.unlock_key', 'world.observatory')->json('data.id');
    $trip = $this->postJson($base.'/travel', $travel)
        ->assertCreated()->assertJsonPath('data.attributes.destination_id', $destination)->json('data.id');
    $this->postJson($base.'/travel', $travel)->assertCreated()->assertJsonPath('data.id', $trip);
    $this->deleteJson($base.'/unlocks/'.$unlock)->assertOk()->assertJsonPath('data.attributes.status', 'revoked');
    $this->postJson($base.'/travel', [...$travel, 'idempotency_key' => 'another-trip'])->assertUnprocessable();

    $player->setRelation('currentTeam', Team::factory()->create());
    $this->getJson($url)->assertNotFound();
    $this->getJson($base)->assertOk()->assertJsonCount(0, 'data');
    $this->postJson($base.'/travel', ['destination_id' => $destination])->assertNotFound();
});
