<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Liberu\BrowserGame\Commerce\CommerceServiceProvider;
use Liberu\BrowserGame\Commerce\Support\CommerceManager;
use Liberu\BrowserGame\CommerceApi\CommerceApiServiceProvider;

it('exposes a team storefront and settles checkout and refund for the order owner', function (): void {
    $this->app->register(CommerceServiceProvider::class);
    $this->app->register(CommerceApiServiceProvider::class);
    $this->artisan('migrate')->assertSuccessful();

    $buyer = User::factory()->create();
    $teammate = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $buyer->id]);
    foreach ([$buyer, $teammate] as $user) {
        $user->forceFill(['current_team_id' => $team->getKey()])->save();
    }
    Sanctum::actingAs($buyer);

    $manager = app(CommerceManager::class);
    $store = $manager->define('Harbor Shop', teamId: (string) $team->getKey());
    $product = $manager->createProduct('MAP-1', 'Harbor Map', 'GLD', 25, ['item' => 'map'], teamId: (string) $team->getKey());
    $base = '/api/v1/browser-game/commerce';
    $this->getJson($base.'?page_size=1')->assertOk()
        ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $store->getKey());
    $this->getJson($base.'/'.$store->getKey())->assertOk()->assertJsonPath('data.attributes.name', 'Harbor Shop');
    $this->getJson($base.'/products')->assertOk()->assertJsonPath('data.0.attributes.sku', 'MAP-1');

    $checkout = ['lines' => [['product_id' => $product->getKey(), 'quantity' => 1]], 'idempotency_key' => 'harbor-map'];
    $order = $this->postJson($base.'/checkout', $checkout)->assertCreated()
        ->assertJsonPath('data.attributes.total', 25)->json('data.id');
    $this->postJson($base.'/checkout', $checkout)->assertCreated()->assertJsonPath('data.id', $order);
    $orderUrl = $base.'/orders/'.$order;

    Sanctum::actingAs($teammate);
    $this->postJson($orderUrl.'/complete')->assertNotFound();
    $this->postJson($orderUrl.'/refund')->assertNotFound();

    Sanctum::actingAs($buyer);
    $this->postJson($orderUrl.'/complete')->assertOk()
        ->assertJsonPath('data.attributes.status', 'completed')
        ->assertJsonPath('data.attributes.entitlements.0.status', 'granted');
    $this->postJson($orderUrl.'/refund')->assertOk()
        ->assertJsonPath('data.attributes.status', 'refunded')
        ->assertJsonPath('data.attributes.entitlements.0.status', 'revoked');
});
