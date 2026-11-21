<?php

use App\Filament\Admin\Resources\NpcResource;
use App\Models\Npc;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Gate as AuthorizationGate;
use Illuminate\Support\Facades\Gate;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\RolesPermissions\Models\Role;

dataset('NPC resource authorization actions', [
    ['authorizeViewAny', false],
    ['authorizeCreate', false],
    ['authorizeEdit', true],
]);

function createNpcSuperAdmin(): User
{
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    setPermissionsTeamId($team->id);
    $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web', 'team_id' => $team->id]);
    $user->assignRole($role);

    return $user;
}

it('enforces NPC resource restrictions even when a generic policy grants access', function (string $authorization, bool $requiresRecord) {
    $user = User::factory()->create();
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Gate::before(fn () => true);
    $arguments = $requiresRecord ? [new Npc()] : [];

    expect($user->isSuperAdmin())->toBeFalse();
    expect(fn () => NpcResource::{$authorization}(...$arguments))->toThrow(AuthorizationException::class);
})->with('NPC resource authorization actions');

it('preserves policy denials for an actor allowed by the NPC resource', function (string $authorization, bool $requiresRecord) {
    $user = createNpcSuperAdmin();
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $gate = new AuthorizationGate($this->app, fn () => $user);
    $gate->before(fn () => false);
    Gate::swap($gate);
    $arguments = $requiresRecord ? [new Npc()] : [];

    expect($user->isSuperAdmin())->toBeTrue();
    expect(fn () => NpcResource::{$authorization}(...$arguments))->toThrow(AuthorizationException::class);
})->with('NPC resource authorization actions');

it('allows a privileged NPC actor when the policy also permits the action', function (string $authorization, bool $requiresRecord) {
    $user = createNpcSuperAdmin();
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $arguments = $requiresRecord ? [new Npc()] : [];

    expect($user->isSuperAdmin())->toBeTrue();
    NpcResource::{$authorization}(...$arguments);
})->with('NPC resource authorization actions');

it('forbids NPC deletion even for a privileged actor with an allowing policy', function () {
    $this->actingAs(createNpcSuperAdmin());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Gate::before(fn () => true);

    expect(NpcResource::getDeleteAuthorizationResponse(new Npc())->denied())->toBeTrue();
});
