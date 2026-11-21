<?php

declare(strict_types=1);

use Illuminate\Foundation\PackageManifest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Liberu\BrowserGame\Items\ItemsServiceProvider;
use Liberu\Foundation\ModuleManager\Cache\RegistryCache;
use Liberu\Foundation\ModuleManager\ModuleActivations;
use Liberu\Foundation\ModuleManager\ModuleInventory;
use Liberu\Foundation\ModuleManager\ModuleLoader;
use Liberu\Foundation\ModuleManager\ModuleRegistry;
use Liberu\Foundation\ModuleManager\ModuleValidator;

it('searches features across installed modules case insensitively', function (): void {
    $exitCode = Artisan::call('module:features', ['query' => 'TRANSACTIONAL PLAYER INVENTORY']);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('browser-game-items', 'Transactional player inventory')
        ->not->toContain('Item catalogue lifecycle');
});

it('warns about a provider bypassing the module manager and fails strict validation', function (): void {
    $this->mock(PackageManifest::class, function ($manifest): void {
        $manifest->shouldReceive('providers')->andReturn([ItemsServiceProvider::class]);
    });
    $warning = 'browser-game-items: Laravel package discovery registers its provider';

    $this->artisan('module:validate')
        ->expectsOutputToContain($warning)
        ->expectsOutput('All discovered modules are valid.')
        ->assertSuccessful();
    $this->artisan('module:validate', ['--strict' => true])
        ->expectsOutputToContain($warning)
        ->assertFailed();
});

it('reports malformed module manifests and refuses to disable a required module', function (): void {
    $root = sys_get_temp_dir().'/browser-module-audit-'.Str::uuid();
    $module = $root.'/broken-catalog';
    File::ensureDirectoryExists($module);

    try {
        File::put($module.'/module.json', '{"name":"broken-catalog"}');
        $this->app->instance(ModuleLoader::class, new ModuleLoader(
            app(ModuleValidator::class),
            app(RegistryCache::class),
            [base_path('modules'), $root],
            [],
            [],
            [],
        ));
        $inventory = app(ModuleInventory::class);
        $this->app->instance(ModuleInventory::class, new ModuleInventory(
            $inventory->manifests,
            $inventory->booted,
            required: ['module-manager'],
            refusedDisables: ['module-manager'],
        ));

        $exitCode = Artisan::call('module:validate');

        expect($exitCode)->toBe(1)
            ->and(Artisan::output())->toContain(
                'broken-catalog: Manifest',
                'missing required key',
                'module-manager: the disabled list names a required module',
            );
    } finally {
        File::deleteDirectory($root);
    }
});

it('persists module activation changes while honoring explicit deployment disables', function (): void {
    $this->artisan('migrate', [
        '--path' => base_path('modules/module-manager/database/migrations'),
        '--realpath' => true,
    ])->assertSuccessful();

    $module = 'browser-game-accounts';
    $activations = app(ModuleActivations::class);
    $registry = app(ModuleRegistry::class);

    $activations->set($module, true);
    $choices = $activations->all();
    [$enabled, $disabled] = ModuleActivations::apply([], [], $choices);
    [$restrictedEnabled, $restrictedDisabled] = ModuleActivations::apply([], [$module], $choices);

    expect($choices[$module])->toBeTrue()
        ->and($registry->enabled($module, $enabled, $disabled))->toBeTrue()
        ->and($registry->enabled($module, $restrictedEnabled, $restrictedDisabled))->toBeFalse();

    $activations->set($module, false);
    [$enabled, $disabled] = ModuleActivations::apply([$module], [], $activations->all());

    expect($activations->all()[$module])->toBeFalse()
        ->and($registry->enabled($module, $enabled, $disabled))->toBeFalse();
});
