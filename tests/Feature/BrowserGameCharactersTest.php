<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Liberu\BrowserGame\Characters\Policies\CharacterPolicy;
use Liberu\BrowserGame\Characters\Queries\CharacterQuery;
use Liberu\BrowserGame\Characters\Support\CharactersManager;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->artisan('migrate', [
        '--path' => base_path('modules/browser-game-characters/database/migrations'),
        '--realpath' => true,
    ]);
});

it('allocates skills from a bounded budget and makes retries idempotent', function (): void {
    $manager = app(CharactersManager::class);
    $character = $manager->awardExperience($manager->create('player-1', 'Ada', 'human', 'mage'), 100);

    $updated = $manager->allocateSkills($character, ['arcana' => 2], 'skill-operation-1');
    $retry = $manager->allocateSkills($updated, ['arcana' => 2], 'skill-operation-1');

    expect($updated->skills['arcana'])->toBe(2)
        ->and($updated->available_skill_points)->toBe(3)
        ->and($retry->skills['arcana'])->toBe(2)
        ->and($retry->available_skill_points)->toBe(3);

    expect(fn (): mixed => $manager->allocateSkills($retry, ['arcana' => 4]))
        ->toThrow(ValidationException::class);
});

it('enforces respec budgets, updates profiles, and bounds vitals', function (): void {
    $manager = app(CharactersManager::class);
    $character = $manager->awardExperience($manager->create('player-2', 'Grace', 'human', 'rogue'), 100);
    $updated = $manager->respec($character, ['stealth' => 3]);

    expect($updated->available_skill_points)->toBe(2)
        ->and($updated->respec_count)->toBe(1);

    $profile = $manager->updateProfile($updated, 'Grace Night', 'elf', 'ranger', 'scout');
    expect($profile->name)->toBe('Grace Night')->and($profile->race)->toBe('elf');

    expect(fn (): mixed => $manager->updateVitals($profile, 111, 0))
        ->toThrow(ValidationException::class);
    $vitals = $manager->updateVitals($profile, 40, 12);
    expect($vitals->health)->toBe(40)->and($vitals->mana)->toBe(12);
});

it('scopes duplicate character names by tenant and team', function (): void {
    $manager = app(CharactersManager::class);
    $first = $manager->create('player-1', 'Shared Name', 'human', 'mage', teamId: 'team-1', tenantId: 'tenant-1');
    $second = $manager->create('player-1', 'Shared Name', 'human', 'mage', teamId: 'team-2', tenantId: 'tenant-2');

    expect($second->getKey())->not->toBe($first->getKey())
        ->and(fn (): mixed => $manager->create('player-1', 'Shared Name', 'human', 'mage', teamId: 'team-1', tenantId: 'tenant-1'))
        ->toThrow(ValidationException::class);
});

it('checks ownership, team scope, and world filtering when looking up characters', function (): void {
    $manager = app(CharactersManager::class);
    $first = $manager->create('player-1', 'Ada', 'human', 'mage', worldId: 'world-1', teamId: 'team-1');
    $manager->create('player-1', 'Grace', 'human', 'rogue', worldId: 'world-2', teamId: 'team-1');
    $manager->create('player-2', 'Other', 'human', 'rogue', worldId: 'world-1', teamId: 'team-1');

    $policy = app(CharacterPolicy::class);
    expect($policy->view(null, 'team-1', $first))->toBeFalse()
        ->and($policy->view('player-2', 'team-1', $first))->toBeFalse()
        ->and($policy->manage('player-1', 'team-2', $first))->toBeFalse()
        ->and($policy->manage('player-1', 'team-1', $first))->toBeTrue()
        ->and(app(CharacterQuery::class)->forPlayer('player-1', 'world-1')->pluck('id')->all())->toBe([$first->getKey()]);
});

it('rejects invalid character changes and makes a keyed respec safe to retry', function (): void {
    $manager = app(CharactersManager::class);
    $character = $manager->awardExperience($manager->create('player-1', 'Ada', 'human', 'mage'), 100);
    $manager->create('player-1', 'Grace', 'human', 'rogue');

    expect(fn () => $manager->create('player-1', ' ', 'human', 'mage'))->toThrow(ValidationException::class)
        ->and(fn () => $manager->awardExperience($character, -1))->toThrow(ValidationException::class)
        ->and(fn () => $manager->respec($character, ['arcana' => -1]))->toThrow(ValidationException::class)
        ->and(fn () => $manager->respec($character, ['arcana' => 6]))->toThrow(ValidationException::class)
        ->and(fn () => $manager->allocateSkills($character, ['arcana' => -1]))->toThrow(ValidationException::class)
        ->and(fn () => $manager->updateProfile($character, 'Grace', 'human', 'mage'))->toThrow(ValidationException::class)
        ->and(fn () => $manager->updateProfile($character, 'Ada', 'human', ' '))->toThrow(ValidationException::class);

    $manager->respec($character, ['arcana' => 1], 'reset');
    $retry = $manager->respec($character, ['arcana' => 1], 'reset');
    expect($retry->respec_count)->toBe(1)
        ->and($retry->available_skill_points)->toBe(4)
        ->and($retry->name)->toBe('Ada');
});

it('checks stored stat budgets and vital limits when the caller holds stale data', function (): void {
    $manager = app(CharactersManager::class);
    $character = $manager->awardExperience($manager->create('player-1', 'Ada', 'human', 'mage'), 100);

    expect(fn () => $manager->spendStatPoints($character, ['strength' => -1]))->toThrow(ValidationException::class)
        ->and(fn () => $manager->spendStatPoints($character, ['strength' => 6]))->toThrow(ValidationException::class);
    $manager->spendStatPoints($character, ['strength' => 5]);
    expect(fn () => $manager->spendStatPoints($character, ['strength' => 1]))->toThrow(ValidationException::class)
        ->and($character->fresh()->strength)->toBe(15)
        ->and($character->fresh()->stat_points)->toBe(0);

    $character->fresh()->update(['max_health' => 60, 'health' => 60]);
    expect(fn () => $manager->updateVitals($character, 70, 10))->toThrow(ValidationException::class)
        ->and($character->fresh()->health)->toBe(60);
});

it('reverses and reapplies the character migrations and scoped name index', function (): void {
    $options = ['--path' => base_path('modules/browser-game-characters/database/migrations'), '--realpath' => true];
    $table = 'browser_game_characters';

    $this->artisan('migrate:rollback', $options + ['--step' => 3])->assertSuccessful();
    expect(Schema::hasTable($table))->toBeFalse()
        ->and(Schema::hasTable('users'))->toBeTrue();

    $this->artisan('migrate', $options)->assertSuccessful();
    expect(Schema::hasTable($table))->toBeTrue()
        ->and(Schema::hasColumn($table, 'last_operation_key'))->toBeTrue()
        ->and(Schema::hasIndex($table, ['player_id', 'name', 'tenant_id', 'team_id'], 'unique'))->toBeTrue();
});
