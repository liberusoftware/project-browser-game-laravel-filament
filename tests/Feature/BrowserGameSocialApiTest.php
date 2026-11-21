<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Liberu\BrowserGame\Social\SocialServiceProvider;
use Liberu\BrowserGame\SocialApi\SocialApiServiceProvider;

beforeEach(function (): void {
    $this->app->register(SocialServiceProvider::class);
    $this->app->register(SocialApiServiceProvider::class);
    $this->artisan('migrate')->assertSuccessful();
});

it('lists social groups for the current team and supports member permissions and messaging', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $owner->id]);
    $owner->forceFill(['current_team_id' => $team->getKey()])->save();
    $member->forceFill(['current_team_id' => $team->getKey()])->save();

    Sanctum::actingAs($owner);
    $base = '/api/v1/browser-game/social';
    foreach (['party', 'chat', 'guild', 'alliance'] as $kind) {
        $this->postJson($base.'/groups/'.$kind, ['name' => 'Example '.$kind])
            ->assertCreated()->assertJsonPath('data.attributes.kind', $kind);
    }
    $group = $this->postJson($base, ['name' => 'Guild Chat', 'kind' => 'chat'])
        ->assertCreated()->json('data.id');
    $this->getJson($base.'?page_size=2')
        ->assertOk()->assertJsonPath('total', 5)->assertJsonCount(2, 'data');
    $this->getJson($base.'/'.$group)
        ->assertOk()->assertJsonPath('data.attributes.name', 'Guild Chat');

    $memberId = (string) $member->getKey();
    $this->postJson($base.'/'.$group.'/members', ['actor_id' => $memberId])
        ->assertCreated()->assertJsonPath('data.actor_id', $memberId);
    $this->patchJson($base.'/'.$group.'/permissions', ['member_id' => $memberId, 'permissions' => ['manage' => true]])
        ->assertOk()->assertJsonPath('data.permissions.manage', true);

    Sanctum::actingAs($member);
    $this->postJson($base.'/'.$group.'/messages', ['body' => 'Welcome to the guild.'])
        ->assertCreated()->assertJsonPath('data.attributes.body', 'Welcome to the guild.');
    $this->getJson($base.'/activity')->assertOk()
        ->assertJsonFragment(['target_id' => $group]);

    $other = Team::factory()->create();
    $member->setRelation('currentTeam', $other);
    $this->getJson($base.'/'.$group)->assertNotFound();
    $this->getJson($base)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($base.'/activity')->assertOk()->assertJsonPath('data.total', 0);
    $member->setRelation('currentTeam', null);
    $this->getJson($base.'/activity')->assertNotFound();
});

it('delivers friend requests, mail, and reports through the social API', function (): void {
    $sender = User::factory()->create();
    $recipient = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $sender->id]);
    foreach ([$sender, $recipient] as $user) {
        $user->forceFill(['current_team_id' => $team->getKey()])->save();
    }

    $base = '/api/v1/browser-game/social';
    $recipientId = (string) $recipient->getKey();
    Sanctum::actingAs($sender);
    $friend = $this->postJson($base.'/friends', ['target_id' => $recipientId])
        ->assertCreated()->assertJsonPath('data.attributes.target_id', $recipientId)->json('data.id');
    $this->postJson($base.'/mail', ['recipient_id' => $recipientId, 'body' => 'Welcome aboard.'])
        ->assertCreated()->assertJsonPath('data.attributes.target_id', $recipientId);
    $this->postJson($base.'/reports', ['target_id' => $recipientId, 'reason' => 'Spam'])
        ->assertCreated()->assertJsonPath('data.attributes.kind', 'report');

    Sanctum::actingAs($recipient);
    $this->patchJson($base.'/'.$friend.'/friend-response', ['status' => 'accepted'])
        ->assertOk()->assertJsonPath('data.attributes.status', 'accepted');
});
