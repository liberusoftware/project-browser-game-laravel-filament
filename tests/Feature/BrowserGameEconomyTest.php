<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Liberu\BrowserGame\Economy\Events\EconomyFeeCharged;
use Liberu\BrowserGame\Economy\Models\EconomyWallet;
use Liberu\BrowserGame\Economy\Support\EconomyManager;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->artisan('migrate', [
        '--path' => base_path('modules/browser-game-economy/database/migrations'),
        '--realpath' => true,
    ]);
});

it('normalizes currencies, rejects negative credits, and enforces vendor limits atomically', function (): void {
    $manager = app(EconomyManager::class);
    $manager->define('Gold', ['code' => 'GOLD']);
    $manager->credit('player-1', 'GOLD', 100);
    $vendor = $manager->createVendor('General Store');
    $offer = $manager->addOffer($vendor, 'potion', 'GOLD', 10, 5, 2);

    $manager->purchaseOffer('player-1', $offer, 2);
    expect(fn (): mixed => $manager->purchaseOffer('player-1', $offer, 1))->toThrow(ValidationException::class)
        ->and(EconomyWallet::query()->where('actor_id', 'player-1')->value('currency_code'))->toBe('gold')
        ->and(EconomyWallet::query()->where('actor_id', 'player-1')->value('balance'))->toBe(80);
    expect(fn (): mixed => $manager->credit('player-1', 'gold', -1))->toThrow(ValidationException::class);
});

it('rejects idempotency keys reused by another economy operation', function (): void {
    $manager = app(EconomyManager::class);
    $manager->define('Gold', ['code' => 'GOLD']);
    $manager->credit('player-1', 'gold', 10, idempotencyKey: 'ledger-1');

    expect(fn (): mixed => $manager->credit('player-2', 'gold', 10, idempotencyKey: 'ledger-1'))
        ->toThrow(ValidationException::class);
});

it('keeps scoped currencies and actor balances isolated', function (): void {
    $manager = app(EconomyManager::class);
    $teamOne = $manager->define('Team One Gold', ['code' => 'GOLD'], tenantId: 'tenant-1', teamId: 'team-1');
    $teamTwo = $manager->define('Team Two Gold', ['code' => 'GOLD'], tenantId: 'tenant-1', teamId: 'team-2');

    $manager->credit('player-1', 'gold', 10, tenantId: 'tenant-1', teamId: 'team-1');
    $manager->credit('player-1', 'gold', 30, tenantId: 'tenant-1', teamId: 'team-2');

    expect($teamOne->getKey())->not->toBe($teamTwo->getKey())
        ->and(EconomyWallet::query()->where('actor_id', 'player-1')->where('team_id', 'team-1')->value('balance'))->toBe(10)
        ->and(EconomyWallet::query()->where('actor_id', 'player-1')->where('team_id', 'team-2')->value('balance'))->toBe(30);
});

it('does not replay ledger idempotency keys across scopes', function (): void {
    $manager = app(EconomyManager::class);
    $manager->define('Team One Gold', ['code' => 'GOLD'], tenantId: 'tenant-1', teamId: 'team-1');
    $manager->define('Team Two Gold', ['code' => 'GOLD'], tenantId: 'tenant-2', teamId: 'team-2');
    $manager->credit('player-1', 'gold', 10, idempotencyKey: 'scoped-ledger', tenantId: 'tenant-1', teamId: 'team-1');

    expect(fn (): mixed => $manager->credit('player-1', 'gold', 10, idempotencyKey: 'scoped-ledger', tenantId: 'tenant-2', teamId: 'team-2'))
        ->toThrow(ValidationException::class);
});

it('checks persisted vendor stock before debiting a wallet', function (): void {
    $manager = app(EconomyManager::class);
    $manager->define('Gold');
    $manager->credit('buyer', 'gold', 30, teamId: 'team-1');
    $vendor = $manager->createVendor('Vendor', teamId: 'team-1');

    expect(fn () => $manager->addOffer($vendor, 'potion', 'gold', 10, teamId: 'team-2'))
        ->toThrow(ValidationException::class)
        ->and(fn () => $manager->addOffer($vendor, 'potion', 'gold', 0, teamId: 'team-1'))
        ->toThrow(ValidationException::class);

    $offer = $manager->addOffer($vendor, 'potion', 'gold', 10, 1, teamId: 'team-1');
    $sold = $manager->purchaseOffer('buyer', $offer, teamId: 'team-1');
    foreach ([$offer, $sold] as $candidate) {
        expect(fn () => $manager->purchaseOffer('buyer', $candidate, teamId: 'team-1'))
            ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('quantity'));
    }

    expect($sold->stock)->toBe(0)
        ->and(EconomyWallet::query()->where('team_id', 'team-1')->value('balance'))->toBe(20);
});

it('settles a fee-bearing listing exactly once and rejects conflicting listing retries', function (): void {
    Event::fake([EconomyFeeCharged::class]);
    $manager = app(EconomyManager::class);
    $manager->define('Gold', ['fee_basis_points' => 1000]);
    $manager->credit('buyer', 'gold', 100);
    $listing = $manager->createListing('seller', 'sword', 'gold', 2, 20, idempotencyKey: 'sword');

    expect($manager->createListing('seller', 'sword', 'GOLD', 2, 20, idempotencyKey: 'sword')->is($listing))->toBeTrue()
        ->and(fn () => $manager->createListing('seller', 'sword', 'gold', 2, 21, idempotencyKey: 'sword'))->toThrow(ValidationException::class)
        ->and(fn () => $manager->createListing('seller', 'sword', 'gold', 0, 20))->toThrow(ValidationException::class)
        ->and(fn () => $manager->purchaseListing('seller', $listing))->toThrow(ValidationException::class)
        ->and(fn () => $manager->cancelListing('buyer', $listing))->toThrow(ValidationException::class);

    $sold = $manager->purchaseListing('buyer', $listing);
    expect($sold->status)->toBe('sold')
        ->and($sold->fee)->toBe(4)
        ->and(EconomyWallet::query()->where('actor_id', 'buyer')->value('balance'))->toBe(60)
        ->and(EconomyWallet::query()->where('actor_id', 'seller')->value('balance'))->toBe(36)
        ->and(fn () => $manager->purchaseListing('buyer', $listing))->toThrow(ValidationException::class);
    Event::assertDispatchedTimes(EconomyFeeCharged::class, 1);
});

it('rejects invalid ledger inputs without changing the balance', function (): void {
    $manager = app(EconomyManager::class);
    $manager->define('Gold');
    config(['browser-game.economy.max_transaction' => 100]);
    $manager->credit('player', 'gold', 10);

    expect(fn () => $manager->define('Duplicate Gold', ['code' => 'GOLD']))->toThrow(ValidationException::class)
        ->and(fn () => $manager->debit('player', 'gold', 0))->toThrow(ValidationException::class)
        ->and(fn () => $manager->debit('player', 'gold', 11))->toThrow(ValidationException::class)
        ->and(fn () => $manager->credit('player', 'silver', 1))->toThrow(ValidationException::class)
        ->and(fn () => $manager->credit('player', 'gold', 101))->toThrow(ValidationException::class)
        ->and(fn () => $manager->credit(' ', 'gold', 1))->toThrow(ValidationException::class)
        ->and(fn () => $manager->transfer('player', 'player', 'gold', 1))->toThrow(ValidationException::class)
        ->and(EconomyWallet::query()->where('actor_id', 'player')->value('balance'))->toBe(10);
});

it('rolls back and reapplies the Economy schema', function (): void {
    $options = ['--path' => base_path('modules/browser-game-economy/database/migrations'), '--realpath' => true];
    $tables = array_column(array_filter(Schema::getTables(), fn (array $table): bool => str_starts_with($table['name'], 'browser_game_economy')), 'name');
    expect($tables)->not->toBeEmpty();

    $this->artisan('migrate:rollback', $options + ['--step' => 3])->assertSuccessful();
    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
    expect(Schema::hasTable('users'))->toBeTrue();

    $this->artisan('migrate', $options)->assertSuccessful();
    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }
});
