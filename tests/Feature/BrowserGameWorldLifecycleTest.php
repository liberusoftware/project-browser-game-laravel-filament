<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Liberu\BrowserGame\World\Policies\WorldPolicy;
use Liberu\BrowserGame\World\Support\WorldManager;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->artisan('migrate', [
        '--path' => base_path('modules/browser-game-world/database/migrations'),
        '--realpath' => true,
    ]);
});

it('keeps travel scoped, same-world, and idempotent', function (): void {
    $manager = app(WorldManager::class);
    $origin = $manager->define('tenant-1', 'team-1', 'location', 'Origin', 'origin', worldId: 'world-1');
    $destination = $manager->define('tenant-1', 'team-1', 'location', 'Destination', 'destination', worldId: 'world-1');
    $travel = $manager->travel('player-1', 'tenant-1', 'team-1', $origin, $destination, 'travel-1');
    $retry = $manager->travel('player-1', 'tenant-1', 'team-1', $origin, $destination, 'travel-1');

    expect($retry->getKey())->toBe($travel->getKey());
    expect(fn (): mixed => $manager->travel('player-1', 'tenant-1', 'team-1', $origin, $origin, 'travel-1'))
        ->toThrow(ValidationException::class);
});

it('requires a persisted actor unlock for gated travel', function (): void {
    $manager = app(WorldManager::class);
    $origin = $manager->define('tenant-1', 'team-1', 'location', 'Origin', 'origin', worldId: 'world-1');
    $destination = $manager->define('tenant-1', 'team-1', 'location', 'Locked', 'locked', worldId: 'world-1', unlockKey: 'world.locked');

    expect(fn (): mixed => $manager->travel('player-1', 'tenant-1', 'team-1', $origin, $destination, 'locked-1', ['unlocked' => true]))
        ->toThrow(ValidationException::class);

    $unlock = $manager->grantUnlock('player-1', $destination, 'tenant-1', 'team-1', 'unlock-1');
    expect($unlock->unlock_key)->toBe('world.locked');
    expect($manager->travel('player-1', 'tenant-1', 'team-1', $origin, $destination, 'locked-1')->destination_id)
        ->toBe($destination->getKey());

    $manager->revokeUnlock('player-1', $unlock, 'tenant-1', 'team-1');
    expect(fn (): mixed => $manager->travel('player-1', 'tenant-1', 'team-1', $origin, $destination, 'locked-2'))
        ->toThrow(ValidationException::class);
});

it('defines every supported world catalog kind through typed actions', function (): void {
    $manager = app(WorldManager::class);
    $methods = ['defineRegion', 'defineLocation', 'defineMap', 'defineEncounter', 'defineNpc', 'defineResource', 'defineWeather', 'defineUnlock'];

    foreach ($methods as $index => $method) {
        $entity = $manager->{$method}('tenant-1', 'team-1', "Entity {$index}", "entity-{$index}", worldId: 'world-1');
        expect($entity->kind)->toBe(config('browser-game.world.kinds', ['region', 'location', 'map', 'encounter', 'npc', 'resource', 'weather', 'unlock'])[$index]);
    }
});

it('rejects cross-team and cross-world travel', function (): void {
    $manager = app(WorldManager::class);
    $origin = $manager->define('tenant-1', 'team-1', 'location', 'Origin', 'origin', worldId: 'world-1');
    $otherTeam = $manager->define('tenant-1', 'team-2', 'location', 'Other', 'other', worldId: 'world-1');
    $otherWorld = $manager->define('tenant-1', 'team-1', 'location', 'Other World', 'other-world', worldId: 'world-2');

    expect(fn (): mixed => $manager->travel('player-1', 'tenant-1', 'team-1', $origin, $otherTeam))
        ->toThrow(ValidationException::class);
    expect(fn (): mixed => $manager->travel('player-1', 'tenant-1', 'team-1', $origin, $otherWorld))
        ->toThrow(ValidationException::class);
});

it('updates entities only inside their current context', function (): void {
    $manager = app(WorldManager::class);
    $entity = $manager->define('tenant-1', 'team-1', 'location', 'Old Name', 'old-name');

    $updated = $manager->update($entity, 'tenant-1', 'team-1', 'New Name', 'new-name', 'archived');

    expect($updated->name)->toBe('New Name')->and($updated->status)->toBe('archived');
    expect(fn (): mixed => $manager->update($entity, 'tenant-1', 'team-2', 'Denied', 'denied', 'active'))
        ->toThrow(ValidationException::class);
});

it('enforces world visibility and rejects invalid mutations without changing the entity', function (): void {
    $manager = app(WorldManager::class);
    $entity = $manager->defineLocation('tenant-1', 'team-1', 'Origin', 'origin');
    $policy = app(WorldPolicy::class);

    expect($policy->view('tenant-1', 'team-1', $entity))->toBeTrue()
        ->and($policy->view('tenant-1', 'team-2', $entity))->toBeFalse()
        ->and($policy->manage(null, 'tenant-1', 'team-1', $entity))->toBeFalse()
        ->and($policy->manage('player', 'tenant-1', 'team-1', $entity))->toBeTrue()
        ->and(fn () => $manager->define(null, null, 'unknown', 'Place', 'place'))->toThrow(ValidationException::class)
        ->and(fn () => $manager->defineLocation(null, null, ' ', 'place'))->toThrow(ValidationException::class)
        ->and(fn () => $manager->update($entity, 'tenant-1', 'team-1', ' ', 'origin', 'active'))->toThrow(ValidationException::class)
        ->and(fn () => $manager->update($entity, 'tenant-1', 'team-1', 'Renamed', 'renamed', 'unknown'))->toThrow(ValidationException::class)
        ->and($entity->refresh()->name)->toBe('Origin');
});

it('rejects blank travel actors and travel keys reused for a new destination', function (): void {
    $manager = app(WorldManager::class);
    $first = $manager->defineLocation(null, null, 'First', 'first');
    $second = $manager->defineLocation(null, null, 'Second', 'second');
    expect(fn () => $manager->travel(' ', null, null, null, $first))->toThrow(ValidationException::class);

    $manager->travel('player', null, null, null, $first, 'journey');
    expect(fn () => $manager->travel('player', null, null, null, $second, 'journey'))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('idempotency_key'));
});

it('reuses an existing unlock and rejects conflicting keys or unauthorized revocation', function (): void {
    $manager = app(WorldManager::class);
    $gate = $manager->defineLocation(null, null, 'Gate', 'gate', unlockKey: 'gate');
    $cave = $manager->defineLocation(null, null, 'Cave', 'cave', unlockKey: 'cave');
    $ungated = $manager->defineLocation(null, null, 'Open', 'open');

    expect(fn () => $manager->grantUnlock(' ', $gate))->toThrow(ValidationException::class)
        ->and(fn () => $manager->grantUnlock('player', $ungated))->toThrow(ValidationException::class);

    $first = $manager->grantUnlock('player', $gate->getKey());
    $assignedKey = $manager->grantUnlock('player', $gate, idempotencyKey: 'unlock-1');
    expect($assignedKey->is($first))->toBeTrue()
        ->and($assignedKey->idempotency_key)->toBe('unlock-1')
        ->and($manager->grantUnlock('player', $gate, idempotencyKey: 'unlock-1')->is($first))->toBeTrue()
        ->and(fn () => $manager->grantUnlock('player', $cave, idempotencyKey: 'unlock-1'))->toThrow(ValidationException::class)
        ->and(fn () => $manager->revokeUnlock('other-player', $assignedKey))->toThrow(ValidationException::class)
        ->and($manager->hasUnlock('player', 'gate'))->toBeTrue();

    $revoked = $manager->revokeUnlock('player', $assignedKey->getKey());
    $retry = $manager->revokeUnlock('player', $assignedKey->getKey());
    expect($retry->status)->toBe('revoked')
        ->and($retry->revoked_at->equalTo($revoked->revoked_at))->toBeTrue()
        ->and($manager->hasUnlock('player', 'gate'))->toBeFalse();
});

it('reverses and reapplies the world schema', function (): void {
    $options = ['--path' => base_path('modules/browser-game-world/database/migrations'), '--realpath' => true];
    $tables = ['browser_game_world_entities', 'browser_game_world_travels', 'browser_game_world_unlocks'];

    $this->artisan('migrate:rollback', $options + ['--step' => 2])->assertSuccessful();
    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
    expect(Schema::hasTable('users'))->toBeTrue();

    $this->artisan('migrate', $options)->assertSuccessful();
    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }
});
