<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Liberu\BrowserGame\Social\Support\SocialManager;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->artisan('migrate', [
        '--path' => base_path('modules/browser-game-social/database/migrations'),
        '--realpath' => true,
    ]);
});

it('creates social records idempotently and enforces membership management', function (): void {
    $manager = app(SocialManager::class);
    $record = $manager->create('Guild', 'guild', 'owner-1', teamId: 'team-1', idempotencyKey: 'social-1');
    $retry = $manager->create('Different', 'guild', 'owner-1', teamId: 'team-1', idempotencyKey: 'social-1');
    $member = $manager->addMember($record, 'player-1', requestedBy: 'owner-1');

    expect($retry->getKey())->toBe($record->getKey())
        ->and($member->actor_id)->toBe('player-1');
    expect(fn (): mixed => $manager->addMember($record, 'player-2', requestedBy: 'player-1'))
        ->toThrow(ValidationException::class);
});

it('requires conversation membership before sending chat messages', function (): void {
    $manager = app(SocialManager::class);
    $record = $manager->create('Chat', 'chat', 'owner-1', teamId: 'team-1');

    expect(fn (): mixed => $manager->send('player-1', $record, 'hello'))
        ->toThrow(ValidationException::class);
    $manager->addMember($record, 'player-1', requestedBy: 'owner-1');
    expect($manager->send('player-1', $record->fresh(), 'hello')->body)->toBe('hello');
});

it('supports explicit friends, social groups, mail, permissions, and activity workflows', function (): void {
    $manager = app(SocialManager::class);

    $friend = $manager->createFriendRequest('owner-1', 'player-1', teamId: 'team-1', idempotencyKey: 'friend-1');
    expect($friend->target_id)->toBe('player-1');
    expect($manager->respondToFriendRequest($friend, 'player-1', 'accepted')->status)->toBe('accepted');

    $guild = $manager->createGuild('owner-1', 'Guild', teamId: 'team-1');
    $manager->addMember($guild, 'player-1', requestedBy: 'owner-1');
    expect($manager->updatePermissions($guild->fresh(), 'owner-1', 'player-1', ['manage' => true])->permissions)->toBe(['manage' => true]);

    $mail = $manager->createMail('owner-1', 'player-1', 'Welcome', teamId: 'team-1');
    expect($mail->target_id)->toBe('player-1');
    expect($manager->recordActivity('owner-1', 'guild.viewed', $guild->getKey())->kind)->toBe('guild.viewed');

    $report = $manager->report('owner-1', 'player-2', 'spam', teamId: 'team-1', idempotencyKey: 'report-1');
    expect($manager->report('owner-1', 'different', 'changed', teamId: 'team-1', idempotencyKey: 'report-1')->getKey())->toBe($report->getKey())
        ->and($report->team_id)->toBe('team-1');
});

it('does not replay social idempotency keys across tenants', function (): void {
    $manager = app(SocialManager::class);
    $manager->createGuild('owner-1', 'Tenant one guild', tenantId: 'tenant-1', teamId: 'team-1', idempotencyKey: 'scoped-social');

    expect(fn (): mixed => $manager->createGuild('owner-1', 'Tenant two guild', tenantId: 'tenant-2', teamId: 'team-2', idempotencyKey: 'scoped-social'))
        ->toThrow(ValidationException::class);
});

it('only lets the recipient respond once to an active friend request', function (): void {
    $manager = app(SocialManager::class);
    expect(fn () => $manager->createFriendRequest('owner', 'owner'))->toThrow(ValidationException::class);
    $request = $manager->createFriendRequest('owner', 'recipient');

    expect(fn () => $manager->respondToFriendRequest($request, 'owner', 'accepted'))->toThrow(ValidationException::class)
        ->and(fn () => $manager->respondToFriendRequest($request, 'recipient', 'unknown'))->toThrow(ValidationException::class);

    $manager->respondToFriendRequest($request, 'recipient', 'declined');
    expect(fn () => $manager->respondToFriendRequest($request, 'recipient', 'accepted'))->toThrow(ValidationException::class)
        ->and($request->refresh()->status)->toBe('declined');
});

it('rejects invalid social operations and keeps membership changes scoped to managers', function (): void {
    $manager = app(SocialManager::class);
    $legacy = $manager->define('Legacy guild', ['description' => 'Existing group'], 'tenant-1', 'team-1');
    $guild = $manager->createGuild('owner', 'Guild');
    $membership = $manager->addMember($guild, 'member', requestedBy: 'owner');
    $mail = $manager->createMail('owner', 'member', 'Hello');
    $manager->report('owner', 'member', 'spam', idempotencyKey: 'report');

    expect($legacy->refresh()->status)->toBe('active')
        ->and($legacy->data)->toBe(['description' => 'Existing group'])
        ->and($membership->social->is($guild))->toBeTrue()
        ->and(fn () => $manager->define(' '))->toThrow(ValidationException::class)
        ->and(fn () => $manager->create(' ', 'guild', 'owner'))->toThrow(ValidationException::class)
        ->and(fn () => $manager->create('Unknown', 'unknown', 'owner'))->toThrow(ValidationException::class)
        ->and(fn () => $manager->createMail('owner', '', 'Hello'))->toThrow(ValidationException::class)
        ->and(fn () => $manager->updatePermissions($mail, 'owner', 'member', ['manage' => true]))->toThrow(ValidationException::class)
        ->and(fn () => $manager->updatePermissions($guild, 'member', 'member', ['manage' => true]))->toThrow(ValidationException::class)
        ->and(fn () => $manager->recordActivity('owner', ' '))->toThrow(ValidationException::class)
        ->and(fn () => $manager->addMember($mail, 'third'))->toThrow(ValidationException::class)
        ->and(fn () => $manager->send('owner', $guild, ' '))->toThrow(ValidationException::class)
        ->and(fn () => $manager->report('owner', 'member', ' '))->toThrow(ValidationException::class)
        ->and(fn () => $manager->report('other', 'member', 'spam', idempotencyKey: 'report'))->toThrow(ValidationException::class)
        ->and($membership->refresh()->permissions)->toBe([]);
});

it('reverses and reapplies the social module schema', function (): void {
    $options = ['--path' => base_path('modules/browser-game-social/database/migrations'), '--realpath' => true];
    $tables = ['browser_game_social', 'browser_game_social_memberships', 'browser_game_social_activity'];

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
