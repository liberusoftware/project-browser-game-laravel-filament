<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Liberu\BrowserGame\Accounts\AccountsServiceProvider;
use Liberu\BrowserGame\Accounts\Support\AccountsManager;
use Liberu\BrowserGame\AccountsLivewire\AccountsLivewireServiceProvider;
use Liberu\BrowserGame\AccountsLivewire\Livewire\AccountsCatalog;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->app->register(AccountsServiceProvider::class);
    $this->app->register(AccountsLivewireServiceProvider::class);
    $this->artisan('migrate')->assertSuccessful();
});

it('renders and completes the account privacy and lifecycle interactions', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $user->forceFill(['current_team_id' => $team->getKey()])->save();
    $account = app(AccountsManager::class)->define('Interactive account', teamId: (string) $team->getKey());

    Livewire::actingAs($user)
        ->test(AccountsCatalog::class)
        ->assertSee('Interactive account')
        ->set('birthYear', 2000)
        ->set('region', 'GB')
        ->set('ageVerified', true)
        ->call('updateAgeRegion', (string) $account->getKey())
        ->set('profileVisibility', 'friends')
        ->call('updatePrivacy', (string) $account->getKey())
        ->call('suspend', (string) $account->getKey())
        ->call('reactivate', (string) $account->getKey())
        ->set('banReason', 'Abusive behavior')
        ->call('ban', (string) $account->getKey());

    $banId = $account->fresh()->bans()->latest()->value('id');

    Livewire::actingAs($user)
        ->test(AccountsCatalog::class)
        ->call('liftBan', (string) $account->getKey(), (int) $banId)
        ->call('requestDeletion', (string) $account->getKey())
        ->call('completeDeletion', (string) $account->getKey())
        ->assertSee('Account deletion completed.');

    expect($account->fresh()->status)->toBe('closed');
});

it('updates and verifies account identity and manages recovery and sessions through Livewire', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->getKey()]);
    $otherTeam = Team::factory()->create();
    $user->forceFill(['current_team_id' => $team->getKey()])->save();

    $manager = app(AccountsManager::class);
    $account = $manager->define('Original Player', teamId: (string) $team->getKey());
    $outsideAccount = $manager->define('Outside Player', teamId: (string) $otherTeam->getKey());
    $session = $manager->createSession($account);
    $accountId = (string) $account->getKey();

    Livewire::actingAs($user)->test(AccountsCatalog::class)
        ->assertDontSee('Outside Player')
        ->set('name', 'Updated Player')
        ->set('email', 'updated@example.test')
        ->set('username', 'updated-player')
        ->call('updateIdentity', $accountId)
        ->assertDispatched('account-updated')
        ->assertSee('Updated Player')
        ->call('verifyEmail', $accountId)
        ->assertSee('Email verified.')
        ->call('issueRecovery', $accountId)
        ->assertSee('Recovery issued.')
        ->call('revokeAllSessions', $accountId)
        ->assertSee('All sessions revoked.');

    expect($account->refresh()->username)->toBe('updated-player')
        ->and($account->email)->toBe('updated@example.test')
        ->and($account->email_verified_at)->not->toBeNull()
        ->and($account->recoveries()->count())->toBe(1)
        ->and($manager->resolveSession($session['token']))->toBeNull();

    Livewire::actingAs($user)->test(AccountsCatalog::class)
        ->call('issueRecovery', (string) $outsideAccount->getKey())
        ->assertNotFound();
    expect($outsideAccount->recoveries()->exists())->toBeFalse();
});
