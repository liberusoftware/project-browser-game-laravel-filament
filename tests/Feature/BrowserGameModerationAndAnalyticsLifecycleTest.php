<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Liberu\BrowserGame\ModerationAndAnalytics\Events\ModerationAndAnalyticsDefined;
use Liberu\BrowserGame\ModerationAndAnalytics\Events\ModerationAndAnalyticsRecorded;
use Liberu\BrowserGame\ModerationAndAnalytics\Events\ModerationAndAnalyticsResolved;
use Liberu\BrowserGame\ModerationAndAnalytics\Support\ModerationAndAnalyticsManager;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->artisan('migrate', [
        '--path' => base_path('modules/browser-game-moderation-and-analytics/database/migrations'),
        '--realpath' => true,
    ]);
});

it('records reports idempotently and resolves them transactionally', function (): void {
    Event::fake();
    $manager = app(ModerationAndAnalyticsManager::class);
    $record = $manager->record('report', 'Suspicious match', 'moderator-1', 'player-1', ['severity' => 'high'], teamId: 'team-1', idempotencyKey: 'report-1');
    $retry = $manager->record('report', 'Different report', 'moderator-1', 'player-1', [], teamId: 'team-1', idempotencyKey: 'report-1');
    $resolved = $manager->resolve($record, 'dismissed');

    expect($retry->getKey())->toBe($record->getKey())
        ->and($resolved->status)->toBe('dismissed')
        ->and($resolved->resolved_at)->not->toBeNull();
    Event::assertDispatched(ModerationAndAnalyticsResolved::class);
    Event::assertDispatched(ModerationAndAnalyticsRecorded::class);
});

it('exposes typed analytics records and protects idempotency ownership', function (): void {
    $manager = app(ModerationAndAnalyticsManager::class);
    $telemetry = $manager->recordTelemetry('player-1', 'quest.started', ['quest' => 'intro'], teamId: 'team-1', idempotencyKey: 'telemetry-1');
    expect($telemetry->kind)->toBe('telemetry');
    expect(fn (): mixed => $manager->recordTelemetry('player-2', 'other', teamId: 'team-1', idempotencyKey: 'telemetry-1'))
        ->toThrow(ValidationException::class);
    expect($manager->recordFunnel('player-1', 'onboarding', teamId: 'team-1')->kind)->toBe('funnel')
        ->and($manager->recordBalance('balance snapshot', teamId: 'team-1')->kind)->toBe('balance')
        ->and($manager->recordEconomy('economy snapshot', teamId: 'team-1')->kind)->toBe('economy')
        ->and($manager->recordFraud('moderator-1', 'player-1', 'suspicious', teamId: 'team-1')->kind)->toBe('fraud')
        ->and($manager->recordHealth('service health', teamId: 'team-1')->kind)->toBe('health');
});

it('rejects invalid record kinds and empty names', function (): void {
    $manager = app(ModerationAndAnalyticsManager::class);

    expect(fn (): mixed => $manager->record('unknown', 'Record'))
        ->toThrow(ValidationException::class);
    expect(fn (): mixed => $manager->record('report', '  '))
        ->toThrow(ValidationException::class);
});

it('does not replay moderation idempotency keys across tenants', function (): void {
    $manager = app(ModerationAndAnalyticsManager::class);
    $manager->record('report', 'Tenant one report', 'moderator-1', tenantId: 'tenant-1', teamId: 'team-1', idempotencyKey: 'scoped-report');

    expect(fn (): mixed => $manager->record('report', 'Tenant two report', 'moderator-1', tenantId: 'tenant-2', teamId: 'team-2', idempotencyKey: 'scoped-report'))
        ->toThrow(ValidationException::class);
});

it('creates scoped legacy definitions and emits their record identity', function (): void {
    Event::fake([ModerationAndAnalyticsDefined::class]);
    $manager = app(ModerationAndAnalyticsManager::class);
    expect(fn () => $manager->define(' '))->toThrow(ValidationException::class);
    $record = $manager->define('Legacy Rule', ['enabled' => true], 'tenant-1', 'team-1');

    expect($record->refresh())->toMatchArray([
        'name' => 'Legacy Rule', 'status' => 'active', 'tenant_id' => 'tenant-1',
        'team_id' => 'team-1', 'data' => ['enabled' => true],
    ]);
    Event::assertDispatched(ModerationAndAnalyticsDefined::class, fn (ModerationAndAnalyticsDefined $event): bool => $event->recordId === $record->getKey());
});

it('records a sanction and an appeal and rejects an empty appeal reason', function (): void {
    $manager = app(ModerationAndAnalyticsManager::class);
    $sanction = $manager->issueSanction('moderator', 'player', 'Warning');
    expect(fn () => $manager->submitAppeal('player', (string) $sanction->getKey(), ' '))->toThrow(ValidationException::class);
    $appeal = $manager->submitAppeal('player', (string) $sanction->getKey(), 'The report was incorrect');

    expect($sanction->kind)->toBe('sanction')
        ->and($sanction->severity)->toBe('moderate')
        ->and($appeal->kind)->toBe('appeal')
        ->and($appeal->status)->toBe('open')
        ->and($appeal->target_id)->toBe($sanction->getKey())
        ->and($appeal->data['reason'])->toBe('The report was incorrect')
        ->and($manager->resolve($appeal)->status)->toBe('resolved');
});

it('checks valid resolution status and reloads record kind before resolving', function (): void {
    $manager = app(ModerationAndAnalyticsManager::class);
    $report = $manager->record('report', 'Report');
    expect(fn () => $manager->resolve($report, 'unknown'))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('status'));

    $report->fresh()->update(['kind' => 'telemetry']);
    foreach ([$report, $report->fresh()] as $candidate) {
        expect(fn () => $manager->resolve($candidate))
            ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('kind'));
    }
    expect($report->refresh()->resolved_at)->toBeNull()
        ->and($report->status)->toBe('open');
});

it('reverses and reapplies the moderation and analytics schema', function (): void {
    $options = ['--path' => base_path('modules/browser-game-moderation-and-analytics/database/migrations'), '--realpath' => true];
    $table = 'browser_game_moderation_and_analytics';
    $this->artisan('migrate:rollback', $options + ['--step' => 1])->assertSuccessful();
    expect(Schema::hasTable($table))->toBeFalse()
        ->and(Schema::hasTable('users'))->toBeTrue();

    $this->artisan('migrate', $options)->assertSuccessful();
    expect(Schema::hasTable($table))->toBeTrue();
});
