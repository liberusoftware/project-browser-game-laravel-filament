<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use App\Services\TeamManagementService;

it('selects the existing default team and adds its new member only once', function (): void {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $owner->getKey(), 'personal_team' => false]);
    $member = User::factory()->create();
    $service = app(TeamManagementService::class);

    $service->assignUserToDefaultTeam($member);
    $service->assignUserToDefaultTeam($member);

    expect($member->fresh()->current_team_id)->toBe($team->getKey())
        ->and($team->users()->whereKey($member->getKey())->count())->toBe(1)
        ->and($member->ownedTeams()->exists())->toBeFalse();
});
