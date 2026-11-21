<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Liberu\BrowserGame\Collections\Events\CollectionRewardGranted;
use Liberu\BrowserGame\Collections\Models\CollectionProgress;
use Liberu\BrowserGame\Collections\Queries\CollectionsQuery;
use Liberu\BrowserGame\Collections\Support\CollectionsManager;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->artisan('migrate', [
        '--path' => base_path('modules/browser-game-collections/database/migrations'),
        '--realpath' => true,
    ]);
});

it('records collection progress once per idempotency key and grants completion metadata', function (): void {
    Event::fake([CollectionRewardGranted::class]);
    $manager = app(CollectionsManager::class);
    $collection = $manager->defineCollection('First Steps', 'achievement');
    $entry = $manager->addEntry($collection, 'first-win', 'Win once', 2, ['title' => 'Winner']);

    $first = $manager->record('player-1', $collection, $entry->entry_key, 1, 'collection-operation-1');
    $duplicate = $manager->record('player-1', $collection, $entry->entry_key, 1, 'collection-operation-1');
    $completed = $manager->record('player-1', $collection, $entry->entry_key, 1, 'collection-operation-2');
    $afterCompletion = $manager->record('player-1', $collection, $entry->entry_key, 1, 'collection-operation-3');

    expect($first->quantity)->toBe(1)
        ->and($duplicate->quantity)->toBe(1)
        ->and($completed->quantity)->toBe(2)
        ->and($completed->completion_count)->toBe(1)
        ->and($completed->completed_at)->not->toBeNull()
        ->and($completed->reward_claimed_at)->not->toBeNull()
        ->and($afterCompletion->completion_count)->toBe(1)
        ->and($afterCompletion->last_operation_key)->toBe('collection-operation-3')
        ->and($afterCompletion->collection->is($collection))->toBeTrue()
        ->and($entry->collection->is($collection))->toBeTrue()
        ->and(CollectionProgress::query()->count())->toBe(1);
    Event::assertDispatchedTimes(CollectionRewardGranted::class, 1);
});

it('rejects invalid collection definitions, entries and progress without changing records', function (): void {
    $manager = app(CollectionsManager::class);
    $collection = $manager->defineAchievement('Seasonal');
    expect(fn () => $manager->define(' '))->toThrow(ValidationException::class)
        ->and(fn () => $manager->defineCollection('Unknown', 'unknown'))->toThrow(ValidationException::class)
        ->and(fn () => $manager->addEntry($collection, 'wins', 'Wins', 0))->toThrow(ValidationException::class);
    expect($collection->entries()->exists())->toBeFalse();

    $manager->addEntry($collection, 'wins', 'Wins');
    expect(fn () => $manager->record('player-1', $collection, 'wins', 0))->toThrow(ValidationException::class);
    $collection->update(['status' => 'inactive']);
    expect(fn () => $manager->record('player-1', $collection, 'wins'))->toThrow(ValidationException::class)
        ->and(CollectionProgress::query()->exists())->toBeFalse();
});

it('reverses collections lifecycle fields and recreates the collection schema', function (): void {
    $options = ['--path' => base_path('modules/browser-game-collections/database/migrations'), '--realpath' => true];
    $progress = 'browser_game_collection_progress';

    $this->artisan('migrate:rollback', $options + ['--step' => 1])->assertSuccessful();
    expect(Schema::hasColumn($progress, 'completion_count'))->toBeFalse();

    $this->artisan('migrate:rollback', $options + ['--step' => 1])->assertSuccessful();
    expect(Schema::hasTable($progress))->toBeFalse()
        ->and(Schema::hasTable('users'))->toBeTrue();

    $this->artisan('migrate', $options)->assertSuccessful();
    expect(Schema::hasColumn($progress, 'completion_count'))->toBeTrue();
});

it('resets repeatable collection progress for the next completion', function (): void {
    $manager = app(CollectionsManager::class);
    $collection = $manager->defineCollection('Daily Hunt', 'achievement', [], true);
    $entry = $manager->addEntry($collection, 'hunt', 'Complete hunt', 1);

    $first = $manager->record('player-2', $collection, $entry->entry_key, 1, 'repeat-1');
    $second = $manager->record('player-2', $collection, $entry->entry_key, 1, 'repeat-2');

    expect($first->completion_count)->toBe(1)
        ->and($second->completion_count)->toBe(2)
        ->and($second->completed_at)->not->toBeNull();
});

it('exposes typed collection definitions for every documented category', function (): void {
    $manager = app(CollectionsManager::class);

    expect($manager->defineAchievement('Achievement')->kind)->toBe('achievement')
        ->and($manager->defineTitle('Title')->kind)->toBe('title')
        ->and($manager->defineReputation('Reputation')->kind)->toBe('reputation')
        ->and($manager->definePet('Pet')->kind)->toBe('pet')
        ->and($manager->defineMount('Mount')->kind)->toBe('mount')
        ->and($manager->defineHousing('Housing')->kind)->toBe('housing')
        ->and($manager->defineCosmetic('Cosmetic')->kind)->toBe('cosmetic');
});

it('rejects scoped collection progress outside the caller scope', function (): void {
    $manager = app(CollectionsManager::class);
    $collection = $manager->defineAchievement('Team achievement', teamId: 'team-1');
    $entry = $manager->addEntry($collection, 'wins', 'Wins');

    expect(fn (): mixed => $manager->record('player-1', $collection, $entry->entry_key, tenantId: 'tenant-1', teamId: 'team-2'))
        ->toThrow(ValidationException::class);
});

it('provides available and unlocked achievement views', function (): void {
    $manager = app(CollectionsManager::class);
    $query = app(CollectionsQuery::class);
    $available = $manager->defineAchievement('Available achievement');
    $unlocked = $manager->defineAchievement('Unlocked achievement');
    $entry = $manager->addEntry($unlocked, 'wins', 'Win once');

    $manager->record('player-3', $unlocked, $entry->entry_key);

    expect($query->availableAchievements(null, null)->pluck('id')->all())
        ->toContain($available->getKey(), $unlocked->getKey())
        ->and($query->forActor('player-3', null, null)->pluck('id')->all())
        ->toBe([$unlocked->getKey()])
        ->and($query->unlockedForActor('player-3', null, null)->pluck('id')->all())
        ->toBe([$unlocked->getKey()]);
});
