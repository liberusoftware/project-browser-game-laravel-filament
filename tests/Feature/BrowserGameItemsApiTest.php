<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Liberu\BrowserGame\Items\ItemsServiceProvider;
use Liberu\BrowserGame\Items\Support\ItemsManager;
use Liberu\BrowserGame\ItemsApi\ItemsApiServiceProvider;

it('lists team items and persists equipment, durability, and container changes', function (): void {
    $this->app->register(ItemsServiceProvider::class);
    $this->app->register(ItemsApiServiceProvider::class);
    $this->artisan('migrate')->assertSuccessful();

    $player = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $player->id]);
    $player->forceFill(['current_team_id' => $team->getKey()])->save();
    Sanctum::actingAs($player);

    $manager = app(ItemsManager::class);
    $teamId = (string) $team->getKey();
    $sword = $manager->define('Sword', ['slot' => 'weapon', 'max_durability' => 10], teamId: $teamId);
    $bag = $manager->define('Bag', ['type' => 'container'], teamId: $teamId);
    $base = '/api/v1/browser-game/items';
    $this->getJson($base.'?page_size=1')->assertOk()
        ->assertJsonPath('total', 2)->assertJsonCount(1, 'data');
    $this->getJson($base.'/'.$sword->getKey())->assertOk()->assertJsonPath('data.attributes.slot', 'weapon');

    $itemUrl = $base.'/inventory/'.$sword->getKey();
    $this->postJson($itemUrl.'/remove', ['quantity' => 1])->assertUnprocessable();
    $entry = $this->postJson($itemUrl.'/add', ['quantity' => 1])->assertCreated()->json('data.id');
    $container = $this->postJson($base.'/inventory/'.$bag->getKey().'/add', ['quantity' => 1])
        ->assertCreated()->json('data.id');
    $entryUrl = $base.'/inventory/entries/'.$entry;
    $this->postJson($entryUrl.'/equip')->assertOk()->assertJsonPath('data.attributes.equipment_slot', 'weapon');
    $this->postJson($entryUrl.'/unequip')->assertOk()->assertJsonPath('data.attributes.equipment_slot', null);
    $this->postJson($entryUrl.'/bind')->assertOk()->assertJsonPath('data.attributes.is_bound', true);
    $this->postJson($entryUrl.'/durability', ['delta' => -2])->assertOk()->assertJsonPath('data.attributes.durability', 8);
    $this->postJson($entryUrl.'/container', ['container_id' => (int) $container])->assertOk()
        ->assertJsonPath('data.attributes.container_id', (int) $container);
    $this->postJson($entryUrl.'/provenance', ['provenance' => ['source' => 'quest']])->assertOk()
        ->assertJsonPath('data.attributes.provenance.source', 'quest');
    $this->getJson($base.'/inventory/me')->assertOk()->assertJsonCount(2, 'data');
    $this->postJson($itemUrl.'/remove', ['quantity' => 1])->assertOk()->assertJsonPath('data.removed', true);
    $this->getJson($base.'/inventory/me')->assertOk()->assertJsonCount(1, 'data');
});
