<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Carbon;
use Liberu\Foundation\Organizations\Actions\InviteMember;
use Liberu\Foundation\Organizations\Actions\TransferOwnership;
use Liberu\Foundation\Organizations\Models\Organization;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Models\TeamInvitation;
use Liberu\Foundation\Organizations\Policies\TeamPolicy;

it('keeps an organization team visible to members and editable by its owner', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $outside = User::factory()->create();
    $organization = Organization::query()->create([
        'name' => 'Example Organization',
        'slug' => 'example-organization',
        'owner_id' => $owner->getKey(),
        'archived_at' => now(),
    ]);
    $team = Team::factory()->create(['user_id' => $owner->getKey(), 'organization_id' => $organization->getKey()]);
    $team->users()->attach($member);

    $policy = app(TeamPolicy::class);
    expect($organization->refresh()->archived_at)->toBeInstanceOf(Carbon::class)
        ->and($organization->teams()->sole()->is($team))->toBeTrue()
        ->and($policy->viewAny($owner))->toBeTrue()
        ->and($policy->create($member))->toBeTrue()
        ->and($policy->view($member, $team))->toBeTrue()
        ->and($policy->view($outside, $team))->toBeFalse()
        ->and($policy->update($member, $team))->toBeFalse()
        ->and($policy->update($owner, $team))->toBeTrue();

    foreach (['addTeamMember', 'updateTeamMember', 'removeTeamMember', 'delete'] as $ability) {
        expect($policy->{$ability}($owner, $team))->toBeTrue()
            ->and($policy->{$ability}($member, $team))->toBeFalse();
    }

    expect(fn () => app(TransferOwnership::class)->handle($team, $owner->getKey(), $outside->getKey(), true))
        ->toThrow(RuntimeException::class, 'The successor must be an active member.');
    expect($team->refresh()->user_id)->toBe($owner->getKey());
});

it('persists only assignable invitations and hides their token hash', function (): void {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $owner->getKey()]);
    $action = app(InviteMember::class);

    expect(fn () => $action->handle($team->getKey(), 'member@example.test', 'super_admin', $owner->getKey()))
        ->toThrow(InvalidArgumentException::class, 'The role cannot be assigned to a team member.');
    expect(TeamInvitation::query()->where('team_id', $team->getKey())->exists())->toBeFalse();

    $token = $action->handle($team->getKey(), ' MEMBER@EXAMPLE.TEST ', 'member', $owner->getKey());
    $invitation = TeamInvitation::query()->where('team_id', $team->getKey())->sole();

    expect($invitation->team->is($team))->toBeTrue()
        ->and($invitation->email)->toBe('member@example.test')
        ->and($invitation->expires_at->isFuture())->toBeTrue()
        ->and($invitation->token_hash)->toBe(hash('sha256', $token))
        ->and($invitation->toArray())->not->toHaveKey('token_hash');
});
