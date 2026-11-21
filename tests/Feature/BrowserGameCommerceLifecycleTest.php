<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Liberu\BrowserGame\Commerce\Events\CommerceDefined;
use Liberu\BrowserGame\Commerce\Models\CommerceOrder;
use Liberu\BrowserGame\Commerce\Queries\CommerceQuery;
use Liberu\BrowserGame\Commerce\Support\CommerceManager;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->artisan('migrate', [
        '--path' => base_path('modules/browser-game-commerce/database/migrations'),
        '--realpath' => true,
    ]);
});

it('checks out idempotently, completes safely, and revokes entitlements on refund', function (): void {
    $manager = app(CommerceManager::class);
    $product = $manager->createProduct('SWORD-1', 'Sword', 'GLD', 25, ['item' => 'sword'], 2, 1);
    $order = $manager->checkout('player-1', [['product_id' => $product->getKey(), 'quantity' => 1]], 'checkout-1');
    $retry = $manager->checkout('player-1', [['product_id' => $product->getKey(), 'quantity' => 1]], 'checkout-1');

    expect(fn () => $manager->refund($order))->toThrow(ValidationException::class)
        ->and(fn () => $manager->complete($order, 'other-player'))->toThrow(ValidationException::class);
    $manager->complete($order);
    $manager->complete($order->fresh());
    $refunded = $manager->refund($order->fresh());
    $refundRetry = $manager->refund($order);
    expect(fn () => $manager->complete($order))->toThrow(ValidationException::class);

    $line = $order->lines->sole();
    $entitlement = $refunded->entitlements->sole();

    expect($retry->getKey())->toBe($order->getKey())
        ->and($refundRetry->is($refunded))->toBeTrue()
        ->and($refunded->status)->toBe('refunded')
        ->and($entitlement->status)->toBe('revoked')
        ->and($entitlement->order->is($order))->toBeTrue()
        ->and($entitlement->product->is($product))->toBeTrue()
        ->and($line->order->is($order))->toBeTrue()
        ->and($line->product->is($product))->toBeTrue()
        ->and($product->lines()->sole()->is($line))->toBeTrue()
        ->and($product->fresh()->stock)->toBe(1);
});

it('rejects cross-currency and invalid product definitions', function (): void {
    $manager = app(CommerceManager::class);
    $first = $manager->createProduct('GLD-1', 'Gold', 'GLD', 10);
    $second = $manager->createProduct('GEM-1', 'Gem', 'GEM', 10);

    expect(fn (): mixed => $manager->checkout('player-1', [['product_id' => $first->getKey(), 'quantity' => 1], ['product_id' => $second->getKey(), 'quantity' => 1]]))
        ->toThrow(ValidationException::class);
    expect(fn (): mixed => $manager->createProduct('BAD', 'Bad', 'gold', 10))
        ->toThrow(ValidationException::class);
});

it('rejects a checkout idempotency key reused with different lines', function (): void {
    $manager = app(CommerceManager::class);
    $first = $manager->createProduct('GLD-1', 'Gold', 'GLD', 10);
    $second = $manager->createProduct('GLD-2', 'Gem', 'GLD', 20);
    $manager->checkout('player-1', [['product_id' => $first->getKey(), 'quantity' => 1]], 'checkout-conflict');

    expect(fn (): mixed => $manager->checkout('player-1', [['product_id' => $second->getKey(), 'quantity' => 1]], 'checkout-conflict'))
        ->toThrow(ValidationException::class);
});

it('keeps products and orders inside their tenant and team scope', function (): void {
    $manager = app(CommerceManager::class);
    $product = $manager->createProduct('TEAM-A', 'Team A product', 'GLD', 10, data: [], tenantId: 'tenant-a', teamId: 'team-a');

    expect(fn (): mixed => $manager->checkout('player-1', [['product_id' => $product->getKey(), 'quantity' => 1]], 'scope-a', 'tenant-b', 'team-b'))
        ->toThrow(ModelNotFoundException::class);

    $order = $manager->checkout('player-1', [['product_id' => $product->getKey(), 'quantity' => 1]], 'scope-b', 'tenant-a', 'team-a');

    expect(fn (): mixed => $manager->complete($order, 'player-1', 'tenant-b', 'team-b'))
        ->toThrow(ValidationException::class);
});

it('returns global and matching legacy commerce records while hiding other scopes', function (): void {
    $manager = app(CommerceManager::class);
    $global = $manager->define('Public shop');
    $local = $manager->define('Local shop', ['region' => 'north'], 'tenant-a', 'team-a');
    $manager->define('Other team shop', [], 'tenant-a', 'team-b');
    $manager->define('Other tenant shop', [], 'tenant-b', 'team-a');

    expect(fn () => $manager->define(' '))->toThrow(ValidationException::class)
        ->and($local->refresh()->data)->toBe(['region' => 'north'])
        ->and($local->status)->toBe('active')
        ->and(app(CommerceQuery::class)->visible('tenant-a', 'team-a')->pluck('id')->all())
        ->toEqualCanonicalizing([$global->getKey(), $local->getKey()]);
});

it('delivers the CommerceDefined extension event only after its transaction commits', function (): void {
    $observed = [];
    Event::listen(CommerceDefined::class, function (CommerceDefined $event) use (&$observed): void {
        $observed[] = $event->recordId;
    });

    DB::beginTransaction();
    CommerceDefined::dispatch('rolled-back-record');
    expect($observed)->toBeEmpty();
    DB::rollBack();
    expect($observed)->toBeEmpty();

    DB::transaction(function () use (&$observed): void {
        CommerceDefined::dispatch('committed-record');
        expect($observed)->toBeEmpty();
    });

    expect($observed)->toBe(['committed-record']);
});

it('preserves stock and orders when checkout inputs or purchase limits are invalid', function (): void {
    $manager = app(CommerceManager::class);
    $product = $manager->createProduct('LIMITED', 'Limited product', 'GLD', 10, stock: 4, maxPerActor: 1);
    $line = ['product_id' => $product->getKey(), 'quantity' => 1];

    expect(fn () => $manager->createProduct('BAD', 'Bad stock', 'GLD', 10, stock: -1))->toThrow(ValidationException::class)
        ->and(fn () => $manager->createProduct('BAD', 'Bad price', 'GLD', -1))->toThrow(ValidationException::class)
        ->and(fn () => $manager->checkout(' ', [$line]))->toThrow(ValidationException::class)
        ->and(fn () => $manager->checkout('player-1', []))->toThrow(ValidationException::class)
        ->and(fn () => $manager->checkout('player-1', [array_replace($line, ['quantity' => 5])]))->toThrow(ValidationException::class)
        ->and(CommerceOrder::query()->count())->toBe(0);

    $manager->complete($manager->checkout('player-1', [$line]));
    expect(fn () => $manager->checkout('player-1', [$line]))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('quantity'));

    expect($product->refresh()->stock)->toBe(3)
        ->and(CommerceOrder::query()->count())->toBe(1);
});

it('rolls back and reapplies the Commerce schema', function (): void {
    $options = ['--path' => base_path('modules/browser-game-commerce/database/migrations'), '--realpath' => true];
    $tables = array_column(array_filter(Schema::getTables(), fn (array $table): bool => str_starts_with($table['name'], 'browser_game_commerce')), 'name');
    expect($tables)->not->toBeEmpty();

    $this->artisan('migrate:rollback', $options + ['--step' => count(glob($options['--path'].'/*.php'))])->assertSuccessful();
    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
    expect(Schema::hasTable('users'))->toBeTrue();

    $this->artisan('migrate', $options)->assertSuccessful();
    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }
});
