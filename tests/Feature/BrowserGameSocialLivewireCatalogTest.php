<?php

use App\Models\User;
use Liberu\BrowserGame\Social\Models\SocialRecord;
use Liberu\BrowserGame\Social\Support\SocialManager;
use Liberu\BrowserGame\SocialLivewire\Livewire\SocialCatalog;
use Liberu\BrowserGame\SocialLivewire\SocialLivewireServiceProvider;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->artisan('migrate', [
        '--path' => base_path('modules/browser-game-social/database/migrations'),
        '--realpath' => true,
    ]);
    app()->register(SocialLivewireServiceProvider::class);
});

it('renders the scoped social catalog and exposes empty and populated states', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(SocialCatalog::class)->assertSee('No social records are available.');
    app(SocialManager::class)->createGuild('another-user', 'Visible guild');
    Livewire::test(SocialCatalog::class)->assertSee('Visible guild')->assertSee('Join');
});

it('runs authenticated social creation, membership, messaging, and moderation actions', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    $component = Livewire::test(SocialCatalog::class);

    $component->call('createFriend', 'friend-target')->assertSet('message', 'Friend request sent.');
    foreach (['party', 'chat', 'guild', 'alliance'] as $kind) {
        $component->call('createGroup', $kind, ucfirst($kind))->assertSet('message', ucfirst($kind).' created.');
    }
    $component->call('sendMail', 'recipient', 'Welcome')->assertSet('message', 'Mail sent.');
    $component->call('report', 'reported-user', 'spam')->assertSet('message', 'Social report submitted.');

    $guild = SocialRecord::query()->where('kind', 'guild')->firstOrFail();
    $joinable = app(SocialManager::class)->createGuild('another-owner', 'Joinable guild');
    $component->call('join', (string) $joinable->getKey())->assertSet('message', 'Membership updated.');
    app(SocialManager::class)->addMember($guild, 'other-member', requestedBy: (string) $user->getAuthIdentifier());
    $component->call('updatePermissions', (string) $guild->getKey(), 'other-member', ['manage' => true])
        ->assertSet('message', 'Member permissions updated.');

    $chat = SocialRecord::query()->where('kind', 'chat')->firstOrFail();
    $component->call('send', (string) $chat->getKey(), 'Hello, everyone')->assertSet('message', 'Message sent.');
});

it('only lets the friend recipient respond and rejects unsupported group kinds', function (): void {
    $recipient = User::factory()->create();
    $this->actingAs($recipient);
    $friend = app(SocialManager::class)->createFriendRequest('other-user', (string) $recipient->getAuthIdentifier());
    $component = Livewire::test(SocialCatalog::class);

    $component->call('respondToFriend', (string) $friend->getKey(), 'accepted')
        ->assertSet('message', 'Friend request updated.');
    $component->call('createGroup', 'unsupported', 'Invalid group')->assertStatus(422);
});
