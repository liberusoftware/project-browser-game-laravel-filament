<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Liberu\BrowserGame\LiveOps\LiveOpsServiceProvider;
use Liberu\BrowserGame\LiveOpsApi\LiveOpsApiServiceProvider;

it('lists team activities and supports publication, idempotent claims and rollback', function (): void {
    $this->app->register(LiveOpsServiceProvider::class);
    $this->app->register(LiveOpsApiServiceProvider::class);
    $this->artisan('migrate')->assertSuccessful();
    $this->travelTo(now('UTC')->setDate(2026, 1, 10)->setTime(12, 0));

    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $user->forceFill(['current_team_id' => $team->getKey()])->save();
    Sanctum::actingAs($user);

    $base = '/api/v1/browser-game/live-ops';
    $grantId = $this->postJson($base, ['name' => 'Starter Grant', 'kind' => 'grant', 'data' => ['grant' => ['gold' => 10]]])
        ->assertCreated()->assertJsonPath('data.attributes.status', 'draft')->json('data.id');
    $grant = $base.'/'.$grantId;
    $this->getJson($base.'?page_size=1')->assertOk()
        ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $grantId);
    $this->getJson($grant)->assertOk()->assertJsonPath('data.attributes.name', 'Starter Grant');
    $this->postJson($grant.'/claim')->assertUnprocessable();
    $this->postJson($grant.'/publish')->assertOk()->assertJsonPath('data.attributes.status', 'published');
    $claim = $this->postJson($grant.'/claim')->assertCreated()
        ->assertJsonPath('data.attributes.grant.gold', 10)->json('data.id');
    $this->postJson($grant.'/claim')->assertCreated()->assertJsonPath('data.id', $claim);
    $this->postJson($grant.'/rollback', ['reason' => 'Grant window ended'])
        ->assertOk()->assertJsonPath('data.attributes.status', 'rolled_back')->assertJsonPath('data.attributes.version', 2);

    $daily = $this->postJson($base, ['name' => 'Daily Activity', 'kind' => 'daily_activity', 'data' => ['grant' => ['gold' => 2]]])
        ->assertCreated()->json('data.id');
    $this->postJson($base.'/'.$daily.'/publish')->assertOk();
    $dailyUrl = $base.'/daily/'.$daily;
    $this->getJson($dailyUrl.'/status?timezone=UTC')->assertOk()
        ->assertJsonPath('data.claimed', false)->assertJsonPath('data.claim_key', '2026-01-10');
    $dailyClaim = $this->postJson($dailyUrl.'/claim', ['timezone' => 'UTC'])->assertCreated()
        ->assertJsonPath('data.attributes.grant.gold', 2)->json('data.id');
    $this->postJson($dailyUrl.'/claim', ['timezone' => 'UTC'])->assertCreated()->assertJsonPath('data.id', $dailyClaim);
    $this->getJson($dailyUrl.'/status?timezone=UTC')->assertOk()->assertJsonPath('data.claimed', true);

    $user->setRelation('currentTeam', Team::factory()->create());
    $this->getJson($grant)->assertNotFound();
    $this->getJson($base)->assertOk()->assertJsonCount(0, 'data');
});
