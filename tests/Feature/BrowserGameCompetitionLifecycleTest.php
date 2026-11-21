<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Liberu\BrowserGame\Competition\Events\CompetitionDefined;
use Liberu\BrowserGame\Competition\Events\CompetitionMatchResolved;
use Liberu\BrowserGame\Competition\Events\CompetitionRewardGranted;
use Liberu\BrowserGame\Competition\Models\CompetitionRecord;
use Liberu\BrowserGame\Competition\Support\CompetitionManager;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->artisan('migrate', [
        '--path' => base_path('modules/browser-game-competition/database/migrations'),
        '--realpath' => true,
    ]);
});

it('reverses the competition schema and restores the scoped match key', function (): void {
    $options = [
        '--path' => base_path('modules/browser-game-competition/database/migrations'),
        '--realpath' => true,
    ];
    $matches = 'browser_game_competition_matches';

    $this->artisan('migrate:rollback', $options + ['--step' => 1])->assertSuccessful();
    expect(Schema::hasIndex($matches, ['idempotency_key'], 'unique'))->toBeTrue()
        ->and(Schema::hasIndex($matches, ['competition_id', 'idempotency_key'], 'unique'))->toBeFalse();

    $this->artisan('migrate:rollback', $options + ['--step' => 2])->assertSuccessful();
    foreach (['browser_game_competition', $matches, 'browser_game_competition_entries', 'browser_game_competition_rewards', 'browser_game_competition_flags'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
    expect(Schema::hasTable('users'))->toBeTrue();

    $this->artisan('migrate', $options)->assertSuccessful();
    expect(Schema::hasIndex($matches, ['competition_id', 'idempotency_key'], 'unique'))->toBeTrue();
});

it('resolves a match once, grants configured rewards, and ranks players', function (): void {
    Event::fake();
    $manager = app(CompetitionManager::class);
    $competition = $manager->create('Arena', data: ['rewards' => ['winner' => ['gold' => 3]]]);
    $match = $manager->match($competition, 'player-a', 'player-b', 'match-1');

    $resolved = $manager->resolve($match, 'player-a');
    $retry = $manager->resolve($resolved, 'player-a');
    $staleRetry = $manager->resolve($match, 'player-a');

    expect(fn () => $manager->resolve($match, 'player-b'))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('winner'));
    expect(fn () => $manager->resolve($resolved, 'player-b'))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('winner'));

    expect($retry->winner_id)->toBe('player-a')
        ->and($staleRetry->winner_id)->toBe('player-a')
        ->and($match->fresh()->winner_id)->toBe('player-a')
        ->and($competition->entries()->where('actor_id', 'player-a')->value('wins'))->toBe(1)
        ->and($competition->entries()->where('actor_id', 'player-a')->value('points'))->toBe(3)
        ->and($competition->entries()->where('actor_id', 'player-b')->value('wins'))->toBe(0)
        ->and($competition->rewards()->count())->toBe(1)
        ->and($competition->rewards()->where('actor_id', 'player-a')->value('quantity'))->toBe(3);

    Event::assertDispatchedTimes(CompetitionMatchResolved::class, 1);
    Event::assertDispatchedTimes(CompetitionRewardGranted::class, 1);
});

it('blocks rematches and rejects flags for another competition', function (): void {
    $manager = app(CompetitionManager::class);
    $competition = $manager->create('Arena');
    $otherCompetition = $manager->create('Other Arena');
    $match = $manager->match($competition, 'player-a', 'player-b');

    expect(fn (): mixed => $manager->match($competition, 'player-b', 'player-a'))
        ->toThrow(ValidationException::class);

    expect(fn (): mixed => $manager->flagCollusion($otherCompetition, 'player-a', 'suspicious', $match))
        ->toThrow(ValidationException::class);
});

it('rejects entries outside the competition window', function (): void {
    $manager = app(CompetitionManager::class);
    $competition = $manager->create('Scheduled Arena');
    $competition->update(['starts_at' => now()->addMinute()]);

    expect(fn (): mixed => $manager->queue($competition->fresh(), 'player-a'))
        ->toThrow(ValidationException::class);
});

it('provides typed competition factories for pvp, matchmaking, seasons, and leaderboards', function (): void {
    $manager = app(CompetitionManager::class);

    expect($manager->createPvp('PvP')->kind)->toBe('pvp')
        ->and($manager->createMatchmaking('Matchmaking')->kind)->toBe('matchmaking')
        ->and($manager->createSeason('Season')->kind)->toBe('season')
        ->and($manager->createLeaderboard('Leaderboard')->kind)->toBe('leaderboard');
});

it('rejects competition and match idempotency keys reused for different inputs', function (): void {
    $manager = app(CompetitionManager::class);
    $competition = $manager->createPvp('Arena', idempotencyKey: 'competition-1');

    expect(fn (): mixed => $manager->createPvp('Different Arena', idempotencyKey: 'competition-1'))
        ->toThrow(ValidationException::class);

    $manager->match($competition, 'player-a', 'player-b', 'match-1');

    expect(fn (): mixed => $manager->match($competition, 'player-a', 'player-c', 'match-1'))
        ->toThrow(ValidationException::class);
});

it('scopes match idempotency keys to their competition', function (): void {
    $manager = app(CompetitionManager::class);
    $firstCompetition = $manager->createPvp('Arena One');
    $secondCompetition = $manager->createPvp('Arena Two');

    $first = $manager->match($firstCompetition, 'player-a', 'player-b', 'shared-match-key');
    $second = $manager->match($secondCompetition, 'player-a', 'player-b', 'shared-match-key');

    expect($second->getKey())->not->toBe($first->getKey())
        ->and($second->competition_id)->toBe($secondCompetition->getKey());
});

it('keeps legacy competition definition and idempotent creation compatible', function (): void {
    Event::fake([CompetitionDefined::class]);
    $manager = app(CompetitionManager::class);
    $legacy = $manager->define('Legacy Arena', ['season' => 1], 'tenant-1', 'team-1');
    $competition = $manager->create('Arena', idempotencyKey: 'arena');
    $retry = $manager->create('Arena', idempotencyKey: 'arena');

    expect($legacy->refresh())->toMatchArray([
        'status' => 'active', 'data' => ['season' => 1], 'tenant_id' => 'tenant-1', 'team_id' => 'team-1',
    ])->and($retry->is($competition))->toBeTrue();
    Event::assertDispatchedTimes(CompetitionDefined::class, 2);

    expect(fn () => $manager->define(' '))->toThrow(ValidationException::class)
        ->and(fn () => $manager->create(' '))->toThrow(ValidationException::class)
        ->and(fn () => $manager->create('Arena', 'unsupported'))->toThrow(ValidationException::class)
        ->and(CompetitionRecord::query()->count())->toBe(2);
});

it('checks stored enrollment state and preserves idempotent matches after a competition closes', function (): void {
    $manager = app(CompetitionManager::class);
    $competition = $manager->create('Arena');
    $match = $manager->match($competition, 'player-a', 'player-b', 'existing');
    expect(fn () => $manager->queue($competition, ' '))->toThrow(ValidationException::class)
        ->and(fn () => $manager->match($competition, 'player-a', 'player-a'))->toThrow(ValidationException::class);

    $competition->fresh()->update(['ends_at' => now()->subMinute()]);

    expect(fn () => $manager->queue($competition, 'player-c'))->toThrow(ValidationException::class)
        ->and(fn () => $manager->match($competition, 'player-c', 'player-d'))->toThrow(ValidationException::class)
        ->and($manager->match($competition, 'player-b', 'player-a', 'existing')->is($match))->toBeTrue()
        ->and($competition->matches()->count())->toBe(1)
        ->and($competition->entries()->count())->toBe(2);
});

it('links recorded collusion evidence to the correct competition and match', function (): void {
    $manager = app(CompetitionManager::class);
    $competition = $manager->create('Arena');
    $match = $manager->match($competition, 'player-a', 'player-b');
    expect(fn () => $manager->flagCollusion($competition, 'player-a', ' ', $match))
        ->toThrow(ValidationException::class);

    $flag = $manager->flagCollusion($competition, 'player-a', 'Suspicious result', $match);
    $entry = $competition->entries()->where('actor_id', 'player-a')->sole();
    expect($competition->flags()->sole()->is($flag))->toBeTrue()
        ->and($flag->competition->is($competition))->toBeTrue()
        ->and($flag->match->is($match))->toBeTrue()
        ->and($entry->competition->is($competition))->toBeTrue();
});
