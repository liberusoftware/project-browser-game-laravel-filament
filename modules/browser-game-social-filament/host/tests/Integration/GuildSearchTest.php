<?php

use App\Filament\Admin\Resources\GuildResource;
use App\Models\Guild;
use App\Models\User;
use Filament\Facades\Filament;
use Liberu\Foundation\RolesPermissions\Models\Role;

it('finds a guild by its description in the admin search', function (): void {
    $admin = User::factory()->withPersonalTeam()->create();
    $team = $admin->ownedTeams()->firstOrFail();
    $admin->switchTeam($team);
    setPermissionsTeamId($team->id);
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web', 'team_id' => $team->id]));

    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($team);

    $guild = Guild::factory()->create(['name' => 'Amber Wardens', 'description' => 'Guardians of the eastern caravan road']);
    Guild::factory()->create(['name' => 'River Wardens', 'description' => 'Guardians of the western bridge']);

    $results = GuildResource::getGlobalSearchResults('caravan road');

    expect($results->pluck('title')->all())->toBe([$guild->name])
        ->and($results->first()->url)->toBe(GuildResource::getUrl('view', ['record' => $guild]));
});
