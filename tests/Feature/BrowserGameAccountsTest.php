<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Liberu\BrowserGame\Accounts\Support\AccountsManager;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->artisan('migrate', [
        '--path' => base_path('modules/browser-game-accounts/database/migrations'),
        '--realpath' => true,
    ]);
});

it('verifies email, tracks session activity, and rejects sessions for banned accounts', function (): void {
    $manager = app(AccountsManager::class);
    $account = $manager->define('Player One', ['email' => 'player@example.test']);
    $verified = $manager->verifyEmail($account);
    $session = $manager->createSession($verified, '127.0.0.1', 'test-agent');

    expect($verified->email_verified_at)->not->toBeNull()
        ->and($manager->resolveSession($session['token']))->not->toBeNull();

    $manager->ban($verified, 'abuse');
    expect($manager->resolveSession($session['token']))->toBeNull();
    expect(fn (): mixed => $manager->createSession($verified->fresh()))->toThrow(ValidationException::class);
});

it('anonymizes an account and revokes its sessions on completed deletion', function (): void {
    $manager = app(AccountsManager::class);
    $account = $manager->define('Player Two', ['email' => 'two@example.test', 'username' => 'player-two']);
    $session = $manager->createSession($account);
    $manager->requestDeletion($account);
    $privacy = $manager->completeDeletion($account, 'operator-1');

    expect($privacy->deletion_completed_at)->not->toBeNull()
        ->and($account->fresh()->status)->toBe('closed')
        ->and($account->fresh()->email)->toBeNull()
        ->and($manager->resolveSession($session['token']))->toBeNull();
});

it('consumes recovery tokens once and rejects expired tokens', function (): void {
    $manager = app(AccountsManager::class);
    $account = $manager->define('Recovery Player', ['email' => 'recovery@example.test']);
    $issued = $manager->issueRecovery($account);

    expect($manager->consumeRecovery($issued['token'])?->getKey())->toBe($account->getKey())
        ->and($manager->consumeRecovery($issued['token']))->toBeNull();

    $expired = $manager->issueRecovery($account);
    $expired['recovery']->update(['expires_at' => now()->subMinute()]);
    expect($manager->consumeRecovery($expired['token']))->toBeNull();
});

it('rejects invalid identities and keeps uniqueness scoped to the tenant', function (): void {
    $manager = app(AccountsManager::class);
    $identity = ['email' => 'player@example.test', 'username' => 'player-one'];
    $first = $manager->define('Player', $identity, tenantId: 'tenant-1');
    $second = $manager->define('Other Tenant Player', $identity, tenantId: 'tenant-2');

    expect($second->is($first))->toBeFalse()
        ->and(fn () => $manager->define('Duplicate', $identity, tenantId: 'tenant-1'))->toThrow(ValidationException::class)
        ->and(fn () => $manager->define(' ', []))->toThrow(ValidationException::class)
        ->and(fn () => $manager->define('Invalid Email', ['email' => 'bad']))->toThrow(ValidationException::class)
        ->and(fn () => $manager->define('Invalid Username', ['username' => 'a!']))->toThrow(ValidationException::class);

    $withoutEmail = $manager->define('No Email');
    expect(fn () => $manager->verifyEmail($withoutEmail))->toThrow(ValidationException::class)
        ->and($withoutEmail->refresh()->email_verified_at)->toBeNull();
});

it('enforces age and privacy policies and prevents reactivating a banned account', function (): void {
    $manager = app(AccountsManager::class);
    $account = $manager->define('Player');
    $year = (int) now()->format('Y');
    config(['browser-game.accounts.minimum_age' => 13]);

    expect(fn () => $manager->setAgeRegionPolicy($account, $year + 1, 'US', false))->toThrow(ValidationException::class)
        ->and(fn () => $manager->setAgeRegionPolicy($account, $year - 12, 'US', true))->toThrow(ValidationException::class)
        ->and(fn () => $manager->updatePrivacy($account, 'unknown', false, false))->toThrow(ValidationException::class)
        ->and($account->refresh()->birth_year)->toBeNull();

    $verified = $manager->setAgeRegionPolicy($account, $year - 13, 'US', true);
    $privacy = $manager->updatePrivacy($verified, 'friends', false, true);
    $ban = $manager->ban($verified, 'abuse');

    expect($verified->age_verified)->toBeTrue()
        ->and($privacy->account->is($account))->toBeTrue()
        ->and($ban->account->is($account))->toBeTrue()
        ->and(fn () => $manager->reactivate($verified))->toThrow(ValidationException::class);

    $closed = $manager->close($verified);
    expect($closed->status)->toBe('closed')
        ->and($closed->closed_at)->not->toBeNull();
});

it('rolls back and reapplies the Accounts schema without dropping host users', function (): void {
    $options = ['--path' => base_path('modules/browser-game-accounts/database/migrations'), '--realpath' => true];
    $tables = ['browser_game_accounts', 'browser_game_account_sessions', 'browser_game_account_bans', 'browser_game_account_recoveries', 'browser_game_account_privacy'];

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
