<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Liberu\BrowserGame\Economy\EconomyServiceProvider;
use Liberu\BrowserGame\Economy\Support\EconomyManager;
use Liberu\BrowserGame\EconomyApi\EconomyApiServiceProvider;

it('exposes balances and transfers and settles team vendor and marketplace transactions', function (): void {
    $this->app->register(EconomyServiceProvider::class);
    $this->app->register(EconomyApiServiceProvider::class);
    $this->artisan('migrate')->assertSuccessful();

    $buyer = User::factory()->create();
    $seller = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $buyer->id]);
    $buyer->forceFill(['current_team_id' => $team->getKey()])->save();
    $seller->forceFill(['current_team_id' => $team->getKey()])->save();
    Sanctum::actingAs($buyer);

    $manager = app(EconomyManager::class);
    $gold = $manager->define('Gold', ['code' => 'gold'], teamId: (string) $team->getKey());
    $base = '/api/v1/browser-game/economy';
    $this->getJson($base.'?page_size=1')->assertOk()->assertJsonPath('data.0.id', $gold->getKey());
    $this->getJson($base.'/'.$gold->getKey())->assertOk()->assertJsonPath('data.attributes.code', 'gold');

    $currency = ['currency_code' => 'gold'];
    $this->postJson($base.'/wallet/credit', [...$currency, 'amount' => 100])
        ->assertCreated()->assertJsonPath('data.attributes.balance_after', 100);
    $this->postJson($base.'/wallet/debit', [...$currency, 'amount' => 10])
        ->assertCreated()->assertJsonPath('data.attributes.balance_after', 90);
    $transfer = [...$currency, 'recipient_id' => (string) $seller->getKey(), 'amount' => 20, 'idempotency_key' => 'starter-gold'];
    $this->postJson($base.'/transfer', $transfer)->assertOk()
        ->assertJsonPath('data.debit.attributes.balance_after', 70)
        ->assertJsonPath('data.credit.attributes.balance_after', 20);
    $this->postJson($base.'/transfer', $transfer)->assertOk();
    $this->getJson($base.'/wallet')->assertOk()->assertJsonPath('data.0.attributes.balance', 70);

    $vendor = $this->postJson($base.'/vendors', ['name' => 'Harbor Merchant'])->assertCreated()->json('data.id');
    $item = [...$currency, 'item_key' => 'potion', 'unit_price' => 5];
    $offer = $this->postJson($base.'/vendors/'.$vendor.'/offers', [...$item, 'stock' => 3])
        ->assertCreated()->json('data.id');
    $this->getJson($base.'/vendors')->assertOk()->assertJsonPath('data.0.relationships.offers.data.0.id', $offer);
    $this->postJson($base.'/offers/'.$offer.'/purchase')->assertOk()->assertJsonPath('data.attributes.stock', 2);

    Sanctum::actingAs($seller);
    $listing = $this->postJson($base.'/listings', [...$item, 'quantity' => 2])->assertCreated()->json('data.id');
    $cancel = $this->postJson($base.'/listings', [...$item, 'quantity' => 1])->assertCreated()->json('data.id');
    $this->postJson($base.'/listings/'.$cancel.'/cancel')->assertOk()->assertJsonPath('data.attributes.status', 'cancelled');

    Sanctum::actingAs($buyer);
    $this->getJson($base.'/listings')->assertOk()->assertJsonPath('data.0.id', $listing);
    $this->postJson($base.'/listings/'.$listing.'/purchase')->assertOk()->assertJsonPath('data.attributes.status', 'sold');
    $this->getJson($base.'/wallet')->assertOk()->assertJsonPath('data.0.attributes.balance', 55);

    $buyer->setRelation('currentTeam', null);
    $this->postJson($base.'/offers/'.$offer.'/purchase')->assertNotFound();
    $this->getJson($base.'/'.$gold->getKey())->assertNotFound();
});
