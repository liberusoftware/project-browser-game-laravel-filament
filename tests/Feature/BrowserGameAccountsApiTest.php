<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Liberu\BrowserGame\Accounts\AccountsServiceProvider;
use Liberu\BrowserGame\Accounts\Models\AccountsRecord;
use Liberu\BrowserGame\Accounts\Support\AccountsManager;
use Liberu\BrowserGame\AccountsApi\AccountsApiServiceProvider;

it('manages account identity, moderation, privacy, and recovery inside the current team', function (): void {
    $this->app->register(AccountsServiceProvider::class);
    $this->app->register(AccountsApiServiceProvider::class);
    $this->artisan('migrate')->assertSuccessful();

    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $user->forceFill(['current_team_id' => $team->getKey()])->save();
    Sanctum::actingAs($user);

    $base = '/api/v1/browser-game/accounts';
    $profile = ['name' => 'Lyra North', 'email' => 'lyra@example.test', 'username' => 'lyra'];
    $account = $this->postJson($base, $profile)->assertCreated()->json('data.id');
    $url = $base.'/'.$account;
    $this->getJson($base.'?page_size=1')->assertOk()->assertJsonPath('data.0.id', $account);
    $this->getJson($url)->assertOk()->assertJsonPath('data.attributes.username', 'lyra');
    $this->patchJson($url, [...$profile, 'name' => 'Lyra South'])->assertOk()->assertJsonPath('data.attributes.name', 'Lyra South');
    $this->postJson($url.'/verify-email')->assertOk();
    $this->postJson($url.'/age-region', ['age_verified' => true, 'region' => 'GB'])
        ->assertOk()->assertJsonPath('data.attributes.age_verified', true);
    $this->postJson($url.'/suspend')->assertOk()->assertJsonPath('data.attributes.status', 'suspended');
    $this->postJson($url.'/reactivate')->assertOk()->assertJsonPath('data.attributes.status', 'active');
    $ban = $this->postJson($url.'/ban', ['reason' => 'Moderation review'])->assertCreated()->json('data.id');
    $this->postJson($url.'/bans/'.$ban.'/lift')->assertOk()->assertJsonMissing(['revoked_at' => null]);

    $this->putJson($url.'/privacy', ['profile_visibility' => 'private', 'marketing_consent' => false, 'analytics_consent' => false])
        ->assertOk()->assertJsonPath('data.attributes.profile_visibility', 'private');

    $manager = app(AccountsManager::class);
    $record = AccountsRecord::query()->findOrFail($account);
    $session = $manager->createSession($record, '127.0.0.1', 'Testing')['session']->getKey();
    $this->getJson($url.'/sessions')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson($url.'/sessions/'.$session.'/revoke')->assertOk()->assertJsonMissing(['revoked_at' => null]);
    $manager->createSession($record, '127.0.0.1', 'Testing');
    $this->postJson($url.'/sessions/revoke-all')->assertOk()->assertJsonPath('data.revoked', 1);

    $this->postJson($url.'/recovery')->assertAccepted()->assertJsonMissingPath('data.attributes.token');
    $token = $manager->issueRecovery($record)['token'];
    $this->postJson($base.'/recovery/consume', ['token' => $token])
        ->assertOk()->assertJsonPath('data.id', $account);
    $this->postJson($base.'/recovery/consume', ['token' => $token])->assertUnprocessable();

    $this->postJson($url.'/deletion-request')->assertOk()->assertJsonMissing(['deletion_requested_at' => null]);
    $this->postJson($url.'/deletion-complete')->assertOk()->assertJsonMissing(['deletion_completed_at' => null]);
    $user->setRelation('currentTeam', Team::factory()->create());
    $this->getJson($url)->assertNotFound();
});
