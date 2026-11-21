<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Liberu\BrowserGame\Quests\QuestsServiceProvider;
use Liberu\BrowserGame\Quests\Support\QuestsManager;
use Liberu\BrowserGame\QuestsApi\QuestsApiServiceProvider;

it('exposes scoped quest lists and preserves progress through completion and abandonment', function (): void {
    $this->app->register(QuestsServiceProvider::class);
    $this->app->register(QuestsApiServiceProvider::class);
    $this->artisan('migrate')->assertSuccessful();

    $player = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $player->id]);
    $otherTeam = Team::factory()->create();
    $player->forceFill(['current_team_id' => $team->getKey()])->save();
    Sanctum::actingAs($player);

    $manager = app(QuestsManager::class);
    $hunt = $manager->define('First Hunt', 'first-hunt-api', ['tracks' => 2], teamId: (string) $team->getKey());
    $scout = $manager->define('Scout the Forest', 'forest-scout-api', ['scouted' => 1], teamId: (string) $team->getKey());
    $hidden = $manager->define('Other Team Quest', 'other-team-api', ['scouted' => 1], teamId: (string) $otherTeam->getKey());
    $base = '/api/v1/browser-game/quests';
    $huntUrl = $base.'/'.$hunt->getKey();
    $scoutUrl = $base.'/'.$scout->getKey();

    $this->getJson($base.'?page_size=1')->assertOk()
        ->assertJsonPath('total', 2)->assertJsonCount(1, 'data');
    $this->getJson($base.'/available')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson($huntUrl)->assertOk()->assertJsonPath('data.attributes.name', 'First Hunt');
    $this->getJson($base.'/'.$hidden->getKey())->assertNotFound();

    $accepted = $this->postJson($huntUrl.'/accept', [], ['Idempotency-Key' => 'hunt-accept'])
        ->assertCreated()->assertJsonPath('data.attributes.status', 'in_progress')->json('data.id');
    $this->postJson($huntUrl.'/accept', [], ['Idempotency-Key' => 'hunt-accept'])
        ->assertCreated()->assertJsonPath('data.id', $accepted);
    $this->getJson($base.'/active')->assertOk()->assertJsonPath('data.0.id', $hunt->getKey());
    $this->postJson($huntUrl.'/progress', ['progress' => ['tracks' => 1]])
        ->assertOk()->assertJsonPath('data.attributes.progress.tracks', 1);
    $this->postJson($huntUrl.'/complete')->assertUnprocessable();
    $this->postJson($huntUrl.'/complete', ['progress' => ['tracks' => 2]])
        ->assertOk()->assertJsonPath('data.attributes.completion_count', 1);
    $this->getJson($base.'/completed')->assertOk()->assertJsonPath('data.0.id', $hunt->getKey());

    $this->postJson($scoutUrl.'/accept')->assertCreated();
    $this->postJson($scoutUrl.'/abandon')->assertOk()->assertJsonPath('data.attributes.status', 'abandoned');
    $this->getJson($base.'/active')->assertOk()->assertJsonCount(0, 'data');
});
