<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Liberu\BrowserGame\Competition\CompetitionServiceProvider;
use Liberu\BrowserGame\CompetitionApi\CompetitionApiServiceProvider;

it('runs a team competition from registration through its leaderboard and reward claim', function (): void {
    $this->app->register(CompetitionServiceProvider::class);
    $this->app->register(CompetitionApiServiceProvider::class);
    $this->artisan('migrate')->assertSuccessful();

    $winner = User::factory()->create();
    $opponent = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $winner->id]);
    $winner->forceFill(['current_team_id' => $team->getKey()])->save();
    Sanctum::actingAs($winner);

    $base = '/api/v1/browser-game/competition';
    $competition = $this->postJson($base, [
        'name' => 'Training Arena',
        'data' => ['rewards' => ['winner' => ['gold' => 3]]],
    ])->assertCreated()->assertJsonPath('data.attributes.kind', 'pvp')->json('data.id');
    $url = $base.'/'.$competition;
    $actor = (string) $winner->getKey();
    $this->getJson($base.'?page_size=1')->assertOk()
        ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $competition);
    $this->getJson($url)->assertOk()->assertJsonPath('data.attributes.name', 'Training Arena');
    $this->postJson($url.'/queue')->assertCreated()->assertJsonPath('data.attributes.actor_id', $actor);
    $match = $this->postJson($url.'/matches', [
        'player_a' => $actor,
        'player_b' => (string) $opponent->getKey(),
    ])->assertCreated()->json('data.id');
    $this->postJson($base.'/matches/'.$match.'/resolve', ['winner_id' => $actor])
        ->assertOk()->assertJsonPath('data.attributes.winner_id', $actor);
    $this->getJson($url.'/leaderboard?limit=1')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.attributes.wins', 1);
    $reward = $this->getJson($url.'/rewards')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.attributes.quantity', 3)->json('data.0.id');
    $this->postJson($base.'/rewards/'.$reward.'/claim')->assertOk()->assertJsonPath('data.id', $reward)
        ->assertJsonMissing(['claimed_at' => null]);

    $this->postJson($url.'/flags', ['reason' => 'Review competition records'])
        ->assertCreated()->assertJsonPath('data.attributes.match_id', null);
    $this->postJson($url.'/flags', ['reason' => 'Review match records', 'match_id' => $match])
        ->assertCreated()->assertJsonPath('data.attributes.match_id', $match);

    $winner->setRelation('currentTeam', Team::factory()->create());
    $this->getJson($url)->assertNotFound();
    $this->getJson($base)->assertOk()->assertJsonCount(0, 'data');
});
