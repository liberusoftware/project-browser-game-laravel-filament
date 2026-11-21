<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Liberu\BrowserGame\LiveOps\Events\LiveOpsClaimed;
use Liberu\BrowserGame\LiveOps\Events\LiveOpsDefined;
use Liberu\BrowserGame\LiveOps\Models\LiveOpsRecord;
use Liberu\BrowserGame\LiveOps\Support\LiveOpsManager;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->artisan('migrate', [
        '--path' => base_path('modules/browser-game-live-ops/database/migrations'),
        '--realpath' => true,
    ]);
});

it('rolls back and reapplies the Live Ops schema without affecting unrelated tables', function (): void {
    $options = [
        '--path' => base_path('modules/browser-game-live-ops/database/migrations'),
        '--realpath' => true,
    ];
    $tables = ['browser_game_live_ops', 'browser_game_live_ops_claims', 'browser_game_live_ops_rollbacks'];

    $this->artisan('migrate:rollback', $options + ['--step' => 1])->assertSuccessful();
    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
    expect(Schema::hasTable('users'))->toBeTrue();

    $this->artisan('migrate', $options)->assertSuccessful();
    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }
});

it('creates idempotent published activities and claims each actor once', function (): void {
    Event::fake();
    $manager = app(LiveOpsManager::class);
    $record = $manager->create('Founders Grant', 'grant', ['grant' => ['gold' => 10]], teamId: 'team-1', idempotencyKey: 'grant-1');
    $retry = $manager->create('Different Name', 'grant', [], teamId: 'team-1', idempotencyKey: 'grant-1');
    $manager->publish($record);

    $claim = $manager->claim('player-1', $record);
    $retryClaim = $manager->claim('player-1', $record->fresh());

    expect($retry->getKey())->toBe($record->getKey())
        ->and($retryClaim->getKey())->toBe($claim->getKey())
        ->and($claim->grant)->toBe(['gold' => 10]);

    Event::assertDispatched(LiveOpsClaimed::class, 1);
});

it('rejects invalid publication windows and empty rollback actors', function (): void {
    $manager = app(LiveOpsManager::class);
    $record = $manager->create('Scheduled Event', 'event', ['starts_at' => now()->addHour(), 'ends_at' => now()->addMinute()]);

    expect(fn (): mixed => $manager->publish($record))
        ->toThrow(ValidationException::class);
    $record->update(['starts_at' => now()->subMinute(), 'ends_at' => now()->addMinute()]);
    $manager->publish($record->fresh());

    expect(fn (): mixed => $manager->rollback($record, '', 'bad actor'))
        ->toThrow(ValidationException::class);
});

it('does not replay a Live Ops idempotency key across scopes', function (): void {
    $manager = app(LiveOpsManager::class);
    $manager->create('Team One Event', 'event', teamId: 'team-1', idempotencyKey: 'scoped-live-ops-1');

    expect(fn (): mixed => $manager->create('Team Two Event', 'event', teamId: 'team-2', idempotencyKey: 'scoped-live-ops-1'))
        ->toThrow(ValidationException::class);
});

it('provides typed live operations for daily activities, events, seasons, schedules, announcements, and grants', function (): void {
    $manager = app(LiveOpsManager::class);

    expect($manager->createDailyActivity('Daily')->kind)->toBe('daily_activity')
        ->and($manager->createEvent('Event')->kind)->toBe('event')
        ->and($manager->createSeason('Season')->kind)->toBe('season')
        ->and($manager->createSchedule('Schedule')->kind)->toBe('schedule')
        ->and($manager->createAnnouncement('Announcement')->kind)->toBe('announcement')
        ->and($manager->createGrant('Grant')->kind)->toBe('grant');
});

it('defines an active scoped record and announces it once', function (): void {
    Event::fake([LiveOpsDefined::class]);
    $record = app(LiveOpsManager::class)->define('Legacy Activity', ['reward' => 5], 'tenant-1', 'team-1');

    expect($record->refresh())->toMatchArray([
        'name' => 'Legacy Activity',
        'status' => 'active',
        'data' => ['reward' => 5],
        'tenant_id' => 'tenant-1',
        'team_id' => 'team-1',
    ]);
    Event::assertDispatched(LiveOpsDefined::class, fn (LiveOpsDefined $event): bool => $event->recordId === $record->getKey());
    Event::assertDispatchedTimes(LiveOpsDefined::class, 1);
});

it('rejects invalid activity definitions before writing a record', function (string $operation, array $arguments, string $field): void {
    $manager = app(LiveOpsManager::class);

    expect(fn () => $manager->{$operation}(...$arguments))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey($field));
    expect(LiveOpsRecord::query()->count())->toBe(0);
})->with([
    'empty legacy name' => ['define', [' '], 'name'],
    'empty activity name' => ['create', [' ', 'event'], 'name'],
    'unsupported activity kind' => ['create', ['Activity', 'unsupported'], 'kind'],
]);

it('revalidates publication against the stored state when the caller holds a stale draft', function (): void {
    $manager = app(LiveOpsManager::class);
    $record = $manager->createEvent('Scheduled Event');
    $published = $manager->publish($record);

    foreach ([$record, $published] as $candidate) {
        expect(fn () => $manager->publish($candidate))
            ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('status'));
    }
    expect($record->refresh()->status)->toBe('published');

    $draft = $manager->createEvent('Rescheduled Event');
    $draft->fresh()->update(['starts_at' => now()->addHour(), 'ends_at' => now()->addMinute()]);
    expect(fn () => $manager->publish($draft))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('ends_at'));
    expect($draft->refresh()->status)->toBe('draft');
});

it('counts consecutive daily claims using the activity calendar date and resets after a missed day', function (): void {
    $this->travelTo(now('UTC')->setDate(2026, 1, 10)->setTime(7, 55));
    $manager = app(LiveOpsManager::class);
    $record = $manager->publish($manager->createDailyActivity('Daily', ['grant' => ['gold' => 5]]));
    $timezone = 'America/Los_Angeles';
    $first = $manager->claimDaily('player-1', $record, $timezone);

    expect($first->grant)->toBe(['gold' => 5, 'streak' => 1, 'claim_date' => '2026-01-09'])
        ->and($first->liveOps->is($record))->toBeTrue()
        ->and($manager->claimDaily('player-1', $record, $timezone)->is($first))->toBeTrue();

    $this->travel(10)->minutes();
    $second = $manager->claimDaily('player-1', $record, $timezone);
    expect($second->grant)->toBe(['gold' => 5, 'streak' => 2, 'claim_date' => '2026-01-10'])
        ->and($manager->dailyStatus('player-1', $record, $timezone)['streak'])->toBe(2)
        ->and($record->claims()->count())->toBe(2);

    $this->travel(2)->days();
    expect($manager->claimDaily('player-1', $record, $timezone)->grant['streak'])->toBe(1);
});

it('rejects unavailable and non-daily claims and records rollback history only for valid reasons', function (): void {
    $manager = app(LiveOpsManager::class);
    $record = $manager->publish($manager->createEvent('Future Event', ['starts_at' => now()->addDay()]));

    expect(fn () => $manager->claim('player-1', $record))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('availability'));
    expect(fn () => $manager->claimDaily('player-1', $record))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('kind'));
    expect(fn () => $manager->dailyStatus('player-1', $record))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('kind'));
    expect(fn () => $manager->rollback($record, 'operator-1', ' '))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('reason'));

    expect($record->claims()->exists())->toBeFalse()
        ->and($record->rollbacks()->exists())->toBeFalse();

    $rolledBack = $manager->rollback($record, 'operator-1', 'Event cancelled');
    $history = $rolledBack->rollbacks()->sole();
    expect($history->liveOps->is($record))->toBeTrue()
        ->and($history->reason)->toBe('Event cancelled')
        ->and($history->to_version)->toBe($rolledBack->version)
        ->and($rolledBack->status)->toBe('rolled_back');
});
