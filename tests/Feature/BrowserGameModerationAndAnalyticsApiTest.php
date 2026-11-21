<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Liberu\BrowserGame\ModerationAndAnalytics\ModerationAndAnalyticsServiceProvider;
use Liberu\BrowserGame\ModerationAndAnalyticsApi\ModerationAndAnalyticsApiServiceProvider;

it('records moderation and analytics events and scopes access to the current team', function (): void {
    $this->app->register(ModerationAndAnalyticsServiceProvider::class);
    $this->app->register(ModerationAndAnalyticsApiServiceProvider::class);
    $this->artisan('migrate')->assertSuccessful();

    $player = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $player->id]);
    $player->setRelation('currentTeam', $team);
    Sanctum::actingAs($player);

    $base = '/api/v1/browser-game/moderation-and-analytics';
    $report = $this->postJson($base, ['kind' => 'report', 'name' => 'Abusive chat', 'target_id' => 'player-2'])
        ->assertCreated()->assertJsonPath('data.attributes.status', 'open')->json('data.id');

    foreach ([
        'reports' => 'report',
        'sanctions' => 'sanction',
        'appeals' => 'appeal',
        'telemetry' => 'telemetry',
        'funnels' => 'funnel',
        'balance' => 'balance',
        'economy' => 'economy',
        'fraud' => 'fraud',
        'health' => 'health',
    ] as $endpoint => $kind) {
        $response = $this->postJson($base.'/'.$endpoint, ['name' => $kind, 'target_id' => 'player-2'])
            ->assertCreated()->assertJsonPath('data.attributes.kind', $kind);
        if ($kind === 'appeal') {
            $response->assertJsonPath('data.attributes.data.reason', 'appeal');
        }
    }

    $this->getJson($base.'?page_size=1')->assertOk()->assertJsonPath('total', 10)->assertJsonCount(1, 'data');
    $this->getJson($base.'/'.$report)->assertOk()->assertJsonPath('data.attributes.name', 'Abusive chat');
    $this->postJson($base.'/'.$report.'/resolve', [])->assertOk()->assertJsonPath('data.attributes.status', 'resolved');

    $player->setRelation('currentTeam', Team::factory()->create());
    $this->getJson($base)->assertOk()->assertJsonPath('total', 0);
    $this->getJson($base.'/'.$report)->assertNotFound();
    $this->postJson($base.'/'.$report.'/resolve', [])->assertNotFound();
});
