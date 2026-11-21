<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Liberu\BrowserGame\GameCore\Events\GameWorldUpdated;
use Liberu\BrowserGame\GameCore\Policies\GameCorePolicy;
use Liberu\BrowserGame\GameCore\Queries\GameCoreOverview;
use Liberu\BrowserGame\GameCore\Support\ArrayGameCoreContext;
use Liberu\BrowserGame\GameCore\Support\GameCoreManager;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->artisan('migrate', [
        '--path' => base_path('modules/browser-game-game-core/database/migrations'),
        '--realpath' => true,
    ]);
});

it('rolls back and recreates the Game Core schema without touching host tables', function (): void {
    $tables = [
        'browser_game_worlds',
        'browser_game_clocks',
        'browser_game_rulesets',
        'browser_game_content_versions',
        'browser_game_feature_flags',
        'browser_game_maintenance_states',
    ];
    $migrationPath = [
        '--path' => base_path('modules/browser-game-game-core/database/migrations'),
        '--realpath' => true,
    ];

    $this->artisan('migrate:rollback', $migrationPath + ['--step' => 1])->assertSuccessful();
    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
    expect(Schema::hasTable('users'))->toBeTrue();

    $this->artisan('migrate', $migrationPath)->assertSuccessful();
    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }
});

it('enforces actor and context scope across world lifecycle mutations', function (): void {
    $manager = app(GameCoreManager::class);
    $context = new ArrayGameCoreContext(actor: 'actor-1', tenant: 'tenant-1', team: 'team-1');
    $world = $manager->createWorld($context, 'World', 'world');

    Event::fake();
    $updated = $manager->updateWorld($context, $world, 'Updated World', 'active', ['region' => 'north']);

    expect($updated->status)->toBe('active')->and($updated->metadata)->toBe(['region' => 'north']);
    Event::assertDispatched(GameWorldUpdated::class);
    expect(fn (): mixed => $manager->updateWorld(new ArrayGameCoreContext(actor: 'actor-2', tenant: 'tenant-1', team: 'team-2'), $world, 'Denied', 'active'))
        ->toThrow(ValidationException::class);
});

it('rejects world creation without an actor', function (): void {
    expect(fn (): mixed => app(GameCoreManager::class)->createWorld(new ArrayGameCoreContext(actor: null, tenant: 'tenant-1', team: 'team-1'), 'World', 'world'))
        ->toThrow(ValidationException::class);
});

it('rejects invalid game world mutations before they persist', function (string $operation, array $arguments, string $field): void {
    $manager = app(GameCoreManager::class);
    $context = new ArrayGameCoreContext(actor: 'actor-1', tenant: 'tenant-1', team: 'team-1');
    $world = $manager->createWorld($context, 'World', 'world');

    expect(fn () => $manager->{$operation}($context, $world, ...$arguments))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey($field));

    $overview = app(GameCoreOverview::class)->forWorld($context, $world->getKey());
    expect($overview['world']->name)->toBe('World')
        ->and($overview['world']->status)->toBe('draft')
        ->and($overview['clock'])->toBeNull()
        ->and($overview['ruleset'])->toBeNull()
        ->and($overview['maintenance'])->toBeNull()
        ->and($overview['feature_flags'])->toBeEmpty();
})->with([
    'negative clock speed' => ['setClock', ['2026-01-01', '-1'], 'speed'],
    'unknown world status' => ['updateWorld', ['Renamed', 'unknown'], 'status'],
    'blank world name' => ['updateWorld', [' ', 'active'], 'name'],
    'invalid ruleset version' => ['publishRuleset', [0, []], 'version'],
    'out of range rollout' => ['setFeatureFlag', ['seasonal', true, 101], 'rollout_percentage'],
    'unknown maintenance status' => ['setMaintenance', ['unknown'], 'status'],
]);

it('allows scoped readers to see a world and requires an actor to manage it', function (): void {
    $owner = new ArrayGameCoreContext(actor: 'actor-1', tenant: 'tenant-1', team: 'team-1');
    $reader = new ArrayGameCoreContext(actor: null, tenant: 'tenant-1', team: 'team-1');
    $outsider = new ArrayGameCoreContext(actor: 'actor-2', tenant: 'tenant-1', team: 'team-2');
    $world = app(GameCoreManager::class)->createWorld($owner, 'World', 'world');
    $policy = app(GameCorePolicy::class);

    expect($policy->manage($owner, $world))->toBeTrue()
        ->and($policy->view($reader, $world))->toBeTrue()
        ->and($policy->manage($reader, $world))->toBeFalse()
        ->and($policy->view($outsider, $world))->toBeFalse()
        ->and(fn () => app(GameCoreOverview::class)->forWorld($outsider, $world->getKey()))
        ->toThrow(RuntimeException::class, 'Game world not found in the current context.');
});

it('evaluates feature flags with constraints, deterministic rollout, and world overrides', function (): void {
    $manager = app(GameCoreManager::class);
    $context = new ArrayGameCoreContext(actor: 'actor-1', tenant: 'tenant-1', team: 'team-1');
    $world = $manager->createWorld($context, 'World', 'world');
    $manager->setFeatureFlag($context, null, 'global-event', true, 100, ['team_id' => 'team-1']);
    $worldFlag = $manager->setFeatureFlag($context, $world, 'world-event', true, 100);
    $manager->setFeatureFlag($context, null, 'overridden-event', true, 100);
    $manager->setFeatureFlag($context, $world, 'overridden-event', false, 100);
    $manager->setFeatureFlag($context, $world, 'disabled-world-event', false, 100);

    $overview = app(GameCoreOverview::class);
    $otherWorld = $manager->createWorld($context, 'Other World', 'other-world');
    $manager->setFeatureFlag($context, $otherWorld, 'other-world-only', true);

    expect($overview->isEnabled($context, $world, 'global-event'))->toBeTrue()
        ->and($overview->isEnabled($context, $world, 'world-event'))->toBeTrue()
        ->and($overview->isEnabled($context, $world, 'overridden-event'))->toBeFalse()
        ->and($overview->isEnabled($context, $world, 'disabled-world-event'))->toBeFalse()
        ->and($overview->isEnabled(new ArrayGameCoreContext(actor: 'actor-2', tenant: 'tenant-1', team: 'team-2'), $world, 'global-event'))->toBeFalse()
        ->and($overview->isEnabled($context, $world, 'global-event', ['team_id' => 'team-2']))->toBeFalse()
        ->and($worldFlag->world->is($world))->toBeTrue()
        ->and($overview->enabledFlags($world->getKey())->pluck('key')->all())
        ->toEqualCanonicalizing(['global-event', 'world-event', 'overridden-event']);
});
