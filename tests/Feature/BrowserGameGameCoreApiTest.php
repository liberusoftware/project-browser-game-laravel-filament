<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Liberu\BrowserGame\GameCore\GameCoreServiceProvider;
use Liberu\BrowserGame\GameCoreApi\GameCoreApiServiceProvider;

beforeEach(function (): void {
    $this->app->register(GameCoreServiceProvider::class);
    $this->app->register(GameCoreApiServiceProvider::class);
    $this->artisan('migrate')->assertSuccessful();
});

it('publishes world configuration and exposes the active settings through the game core API', function (): void {
    $endpoint = '/api/v1/browser-game/game-core';
    $this->getJson($endpoint)->assertUnauthorized();
    Sanctum::actingAs(User::factory()->create());

    $world = $this->postJson($endpoint, ['name' => 'Frontier', 'slug' => 'frontier'])
        ->assertCreated()
        ->assertJsonPath('data.attributes.status', 'draft')
        ->json('data.id');

    $this->getJson($endpoint.'?page_size=1')
        ->assertOk()
        ->assertJsonPath('per_page', 1)
        ->assertJsonPath('data.0.id', $world);

    $worldEndpoint = "$endpoint/$world";
    $this->getJson($worldEndpoint)
        ->assertOk()
        ->assertJsonPath('data.attributes.overview.clock', null)
        ->assertJsonPath('data.attributes.overview.feature_flags', []);

    $this->patchJson($worldEndpoint, ['name' => 'New Frontier', 'status' => 'active'])
        ->assertOk()
        ->assertJsonPath('data.attributes.name', 'New Frontier')
        ->assertJsonPath('data.attributes.status', 'active');

    $this->putJson("$worldEndpoint/clock", ['current_at' => '2026-01-01', 'speed' => 2, 'paused' => true])
        ->assertOk()
        ->assertJsonPath('data.attributes.paused', true);

    $this->postJson("$worldEndpoint/rulesets", ['version' => 1, 'rules' => ['max_level' => 50]])
        ->assertCreated()
        ->assertJsonPath('data.attributes.status', 'published');

    $this->postJson("$worldEndpoint/content-versions", ['version' => 2, 'content_hash' => 'frontier-v2', 'manifest' => ['regions' => ['frontier']]])
        ->assertCreated()
        ->assertJsonPath('data.attributes.version', 2);

    $this->putJson("$worldEndpoint/feature-flags/seasonal", ['enabled' => true])
        ->assertOk()
        ->assertJsonPath('data.attributes.rollout_percentage', 100);

    $this->putJson("$worldEndpoint/maintenance", ['status' => 'active', 'message' => 'Deploying new content'])
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'active');

    $this->getJson($worldEndpoint)
        ->assertOk()
        ->assertJsonPath('data.attributes.overview.clock.attributes.paused', true)
        ->assertJsonPath('data.attributes.overview.ruleset.attributes.rules.max_level', 50)
        ->assertJsonPath('data.attributes.overview.content_version.attributes.content_hash', 'frontier-v2')
        ->assertJsonPath('data.attributes.overview.feature_flags.0.attributes.key', 'seasonal')
        ->assertJsonPath('data.attributes.overview.maintenance.attributes.message', 'Deploying new content');
});
