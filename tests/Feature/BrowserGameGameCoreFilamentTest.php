<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Route;
use Liberu\BrowserGame\GameCore\Models\GameFeatureFlag;
use Liberu\BrowserGame\GameCore\Models\GameWorld;
use Liberu\BrowserGame\GameCore\Support\ArrayGameCoreContext;
use Liberu\BrowserGame\GameCore\Support\GameCoreManager;
use Liberu\BrowserGame\GameCoreFilament\GameCoreFilamentPlugin;
use Liberu\BrowserGame\GameCoreFilament\Resources\GameClockResource;
use Liberu\BrowserGame\GameCoreFilament\Resources\GameContentVersionResource;
use Liberu\BrowserGame\GameCoreFilament\Resources\GameFeatureFlagResource;
use Liberu\BrowserGame\GameCoreFilament\Resources\GameFeatureFlagResource\Pages\CreateGameFeatureFlag;
use Liberu\BrowserGame\GameCoreFilament\Resources\GameFeatureFlagResource\Pages\EditGameFeatureFlag;
use Liberu\BrowserGame\GameCoreFilament\Resources\GameMaintenanceStateResource;
use Liberu\BrowserGame\GameCoreFilament\Resources\GameRulesetResource;
use Liberu\BrowserGame\GameCoreFilament\Resources\GameWorldResource;
use Liberu\BrowserGame\GameCoreFilament\Resources\GameWorldResource\Pages\CreateGameWorld;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->artisan('migrate', [
        '--path' => base_path('modules/browser-game-game-core/database/migrations'),
        '--realpath' => true,
    ])->assertSuccessful();
});

it('exposes only eligible game worlds and their records through the Filament resources', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->getKey()]);
    $otherTeam = Team::factory()->create();
    $user->forceFill(['current_team_id' => $team->getKey()])->save();
    $this->actingAs($user);

    $manager = app(GameCoreManager::class);
    $currentContext = new ArrayGameCoreContext('current-actor', null, (string) $team->getKey());
    $otherContext = new ArrayGameCoreContext('other-actor', null, (string) $otherTeam->getKey());
    $currentWorld = $manager->createWorld($currentContext, 'Visible World', 'visible');
    $otherWorld = $manager->createWorld($otherContext, 'Other Team World', 'other-team');

    foreach ([[$currentContext, $currentWorld], [$otherContext, $otherWorld]] as [$context, $world]) {
        $manager->setClock($context, $world, '2026-01-01');
        $manager->publishRuleset($context, $world, 1, ['max_level' => 25]);
        $manager->publishContentVersion($context, $world, 1, 'release-1', ['regions' => ['frontier']]);
        $manager->setFeatureFlag($context, $world, 'seasonal', true);
        $manager->setMaintenance($context, $world, 'scheduled');
    }

    $globalFlag = $manager->setFeatureFlag($currentContext, null, 'global', true);

    $plugin = GameCoreFilamentPlugin::make();
    $panel = Panel::make()->id('game-core-test')->plugin($plugin);
    $resources = [
        GameWorldResource::class => 'name',
        GameClockResource::class => 'current_at',
        GameRulesetResource::class => 'version',
        GameContentVersionResource::class => 'content_hash',
        GameFeatureFlagResource::class => 'key',
        GameMaintenanceStateResource::class => 'status',
    ];

    expect($panel->getPlugin('browser-game-game-core'))->toBe($plugin)
        ->and($panel->getResources())->toEqualCanonicalizing(array_keys($resources))
        ->and(GameWorldResource::getEloquentQuery()->pluck('id')->all())->toBe([$currentWorld->getKey()]);

    foreach ($resources as $resource => $column) {
        $pages = $resource::getPages();
        expect(array_keys($pages))->toBe(['index', 'create', 'edit']);

        $listPage = $pages['index']->getPage();
        $table = $resource::table(Table::make(new $listPage()));
        expect($table->getColumn($column))->not->toBeNull();

        $createPage = $pages['create']->getPage();
        $schema = $resource::form(Schema::make(new $createPage()));
        if ($resource === GameWorldResource::class) {
            expect($schema->getComponent('slug'))->not->toBeNull();

            continue;
        }

        $worldField = $schema->getComponent('world_id');
        expect($worldField)->toBeInstanceOf(Select::class)
            ->and($worldField->getOptions())->toBe([$currentWorld->getKey() => 'Visible World']);

        $records = $resource::getEloquentQuery()->pluck('world_id', 'id');
        $expectedWorlds = $resource === GameFeatureFlagResource::class
            ? [$currentWorld->getKey(), null]
            : [$currentWorld->getKey()];
        expect($records->values()->all())->toEqualCanonicalizing($expectedWorlds);
    }

    expect(GameFeatureFlagResource::getEloquentQuery()->find($globalFlag->getKey())?->key)->toBe('global');
});

it('scopes new worlds to the current team and records the author of feature flag changes', function (): void {
    $panel = Panel::make()->id('game-core-test')->path('game-core-test')->plugin(GameCoreFilamentPlugin::make());
    Filament::registerPanel($panel);
    Filament::setCurrentPanel($panel);
    Filament::bootCurrentPanel();

    Route::prefix($panel->getPath())->name($panel->generateRouteName(''))->group(function () use ($panel): void {
        foreach ($panel->getResources() as $resource) {
            $resource::registerRoutes($panel);
        }
    });
    Route::getRoutes()->refreshNameLookups();

    $user = User::factory()->create();
    $this->actingAs($user);
    $worldData = ['name' => 'Test World', 'slug' => 'test-world', 'status' => 'draft'];

    Livewire::test(CreateGameWorld::class)->fillForm($worldData)->call('create')->assertForbidden();
    expect(GameWorld::query()->where('slug', 'test-world')->exists())->toBeFalse();

    $team = Team::factory()->create(['user_id' => $user->getKey()]);
    $user->forceFill(['current_team_id' => $team->getKey()])->save();
    $this->actingAs($user->fresh());

    Livewire::test(CreateGameWorld::class)->fillForm($worldData)->call('create')->assertHasNoFormErrors()->assertRedirect();
    $world = GameWorld::query()->where('slug', 'test-world')->sole();
    expect((string) $world->team_id)->toBe((string) $team->getKey());

    Livewire::test(CreateGameFeatureFlag::class)
        ->fillForm(['world_id' => $world->getKey(), 'key' => 'seasonal', 'enabled' => true, 'rollout_percentage' => 100])
        ->call('create')->assertHasNoFormErrors()->assertRedirect();

    $flag = GameFeatureFlag::query()->where('key', 'seasonal')->sole();
    expect((string) $flag->changed_by)->toBe((string) $user->getKey());

    $editor = User::factory()->create();
    $team->users()->attach($editor);
    $editor->forceFill(['current_team_id' => $team->getKey()])->save();
    $this->actingAs($editor);

    Livewire::test(EditGameFeatureFlag::class, ['record' => $flag->getKey()])
        ->fillForm(['rollout_percentage' => 25])
        ->call('save')->assertHasNoFormErrors();

    expect($flag->refresh()->rollout_percentage)->toBe(25)
        ->and((string) $flag->changed_by)->toBe((string) $editor->getKey());
});
