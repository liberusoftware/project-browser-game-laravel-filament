<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Liberu\BrowserGame\Items\ItemsServiceProvider;
use Liberu\Foundation\ModuleManager\Cache\RegistryCache;
use Liberu\Foundation\ModuleManager\Exceptions\ModuleActivationRefused;
use Liberu\Foundation\ModuleManager\Manifest;
use Liberu\Foundation\ModuleManager\ModuleActivations;
use Liberu\Foundation\ModuleManager\ModuleInventory;
use Liberu\Foundation\ModuleManager\ModuleLoader;
use Liberu\Foundation\ModuleManager\ModuleManagement;
use Liberu\Foundation\ModuleManager\ModuleRegistry;
use Liberu\Foundation\ModuleManager\ModuleValidationGuard;
use Liberu\Foundation\ModuleManager\ModuleValidator;

it('persists feature activation only for installed and valid modules', function (): void {
    $files = new Filesystem();
    $root = sys_get_temp_dir().'/module-activation-'.Str::uuid();
    $fixtures = [
        'feature' => ['liberusoftware/module-browser-game-items', 'feature', ItemsServiceProvider::class],
        'locked' => ['liberusoftware/module-browser-game-items', 'feature', ItemsServiceProvider::class],
        'infrastructure' => ['liberusoftware/module-browser-game-items', 'infrastructure', ItemsServiceProvider::class],
        'uninstalled' => ['example/uninstalled', 'feature', ItemsServiceProvider::class],
        'invalid' => ['liberusoftware/module-browser-game-items', 'feature', 'Example\\MissingProvider'],
        'dependent' => ['liberusoftware/module-browser-game-items', 'feature', ItemsServiceProvider::class],
    ];

    try {
        $manifests = [];
        foreach ($fixtures as $name => [$package, $type, $provider]) {
            $path = "$root/$name";
            $files->ensureDirectoryExists($path);
            $files->put("$path/module.json", json_encode([
                'name' => $name, 'display_name' => ucfirst($name), 'description' => 'Activation test fixture',
                'version' => '1.0.0', 'category' => 'capability', 'provider' => $provider,
                'requires' => $name === 'dependent' ? ['capabilities' => ['fixture.missing' => '^1.0']] : [],
                'capabilities' => [], 'features' => [ucfirst($name)], 'default_enabled' => false, 'module_type' => $type,
            ], JSON_THROW_ON_ERROR));
            $files->put("$path/composer.json", json_encode([
                'name' => $package, 'type' => 'liberu-module', 'extra' => ['liberu' => ['name' => $name]],
            ], JSON_THROW_ON_ERROR));
            $manifests[$name] = Manifest::fromFile("$path/module.json");
        }

        $activations = app(ModuleActivations::class);
        $management = new ModuleManagement(
            new ModuleInventory($manifests, []),
            $activations,
            app(ModuleValidator::class),
            app(PackageManifest::class),
            [],
            ['locked'],
        );

        expect(array_keys($management->modules()))->toBe(['dependent', 'feature', 'invalid', 'locked'])
            ->and($management->modules()['feature']['status'])->toBe('disabled')
            ->and($management->modules()['locked']['locked'])->toContain('MODULES_DISABLED');

        $management->enable('feature');
        expect($management->modules()['feature']['status'])->toBe('enabled')
            ->and($activations->all())->toBe(['feature' => true]);

        expect(fn () => $management->enable('uninstalled'))->toThrow(ModuleActivationRefused::class, 'not installed')
            ->and(fn () => $management->enable('infrastructure'))->toThrow(ModuleActivationRefused::class, 'infrastructure')
            ->and(fn () => $management->enable('locked'))->toThrow(ModuleActivationRefused::class, 'MODULES_DISABLED')
            ->and(fn () => $management->enable('invalid'))->toThrow(ModuleActivationRefused::class, 'not autoloadable')
            ->and(fn () => $management->enable('dependent'))->toThrow(ModuleActivationRefused::class, 'fixture.missing');

        expect(fn () => app(ModuleValidationGuard::class)->ensureValid(new ModuleRegistry(['invalid' => $manifests['invalid']]), Application::VERSION))
            ->toThrow(RuntimeException::class, 'not autoloadable');

        $management->disable('feature');
        expect($management->modules()['feature']['status'])->toBe('disabled')
            ->and($activations->all())->toBe(['feature' => false]);
    } finally {
        $files->deleteDirectory($root);
    }
});

it('refuses to overwrite the deployment module cache when its audit fails', function (): void {
    $files = new Filesystem();
    $root = sys_get_temp_dir().'/module-cache-audit-'.Str::uuid();
    $files->ensureDirectoryExists($root.'/broken-module');

    try {
        $files->put($root.'/broken-module/module.json', '{invalid-json}');
        $cache = $root.'/registry.php';
        $files->put($cache, 'existing cache');
        config(['modules.isolate_failures' => true, 'modules.paths' => [$root], 'modules.cache_path' => $cache]);
        $this->app->instance(ModuleLoader::class, new ModuleLoader(
            app(ModuleValidator::class), app(RegistryCache::class), [$root], [], [], [],
        ));

        $this->artisan('module:cache')->expectsOutputToContain('broken-module:')->assertFailed();
        expect($files->get($cache))->toBe('existing cache');
    } finally {
        $files->deleteDirectory($root);
    }
});

it('reports the installed module status in machine-readable output', function (): void {
    expect(Artisan::call('module:list', ['--json' => true]))->toBe(0);
    $rows = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $manager = collect($rows)->firstWhere('module', 'module-manager');

    expect($manager)->toMatchArray(['module' => 'module-manager', 'status' => 'booted', 'problems' => []]);
});
