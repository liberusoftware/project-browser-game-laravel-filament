<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Liberu\BrowserGame\Collections\CollectionsServiceProvider;
use Liberu\BrowserGame\Collections\Models\CollectionsRecord;
use Liberu\BrowserGame\Collections\Support\CollectionsManager;
use Liberu\BrowserGame\CollectionsApi\CollectionsApiServiceProvider;

it('shows scoped collections and keeps achievement progress private to the actor', function (): void {
    $this->app->register(CollectionsServiceProvider::class);
    $this->app->register(CollectionsApiServiceProvider::class);
    $this->artisan('migrate')->assertSuccessful();

    $player = User::factory()->create();
    $teammate = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $player->id]);
    foreach ([$player, $teammate] as $user) {
        $user->forceFill(['current_team_id' => $team->getKey()])->save();
    }
    Sanctum::actingAs($player);

    $base = '/api/v1/browser-game/collections';
    $id = $this->postJson($base, ['name' => 'First Win'])
        ->assertCreated()->assertJsonPath('data.attributes.kind', 'achievement')->json('data.id');
    $record = CollectionsRecord::query()->findOrFail($id);
    app(CollectionsManager::class)->addEntry($record, 'wins', 'Win two matches', 2, teamId: (string) $team->getKey());

    $this->getJson($base.'?page_size=1')->assertOk()
        ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $id);
    $this->getJson($base.'/'.$id)->assertOk()->assertJsonPath('data.attributes.name', 'First Win');
    $this->getJson($base.'/achievements')->assertOk()->assertJsonPath('data.0.id', $id);
    $this->getJson($base.'/achievements/unlocked')->assertOk()->assertJsonCount(0, 'data');

    $progressUrl = $base.'/'.$id.'/progress';
    $payload = ['entry_key' => 'wins'];
    $this->postJson($progressUrl, $payload, ['Idempotency-Key' => 'first-win'])
        ->assertCreated()->assertJsonPath('data.attributes.quantity', 1);
    $this->postJson($progressUrl, $payload, ['Idempotency-Key' => 'first-win'])
        ->assertCreated()->assertJsonPath('data.attributes.quantity', 1);
    $this->postJson($progressUrl, $payload, ['Idempotency-Key' => 'second-win'])
        ->assertCreated()->assertJsonPath('data.attributes.completion_count', 1);
    $this->getJson($base.'/progress')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.attributes.quantity', 2);
    $this->getJson($base.'/achievements/unlocked')->assertOk()->assertJsonPath('data.0.id', $id);

    Sanctum::actingAs($teammate);
    $this->getJson($base.'/progress')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($base.'/achievements/unlocked')->assertOk()->assertJsonCount(0, 'data');

    $teammate->setRelation('currentTeam', Team::factory()->create());
    $this->getJson($base.'/'.$id)->assertNotFound();
    $this->postJson($progressUrl, $payload)->assertNotFound();
});
