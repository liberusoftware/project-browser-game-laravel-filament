<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Liberu\BrowserGame\Items\ItemsServiceProvider;
use Liberu\Foundation\ModuleManager\Cache\RegistryCache;
use Liberu\Foundation\ModuleManager\Exceptions\DependencyResolutionFailed;
use Liberu\Foundation\ModuleManager\Exceptions\InvalidManifest;
use Liberu\Foundation\ModuleManager\Exceptions\RequiredModuleFailed;
use Liberu\Foundation\ModuleManager\Manifest;
use Liberu\Foundation\ModuleManager\ModuleDiscovery;
use Liberu\Foundation\ModuleManager\ModuleInventory;
use Liberu\Foundation\ModuleManager\ModuleLoader;
use Liberu\Foundation\ModuleManager\ModuleRegistry;
use Liberu\Foundation\ModuleManager\ModuleValidator;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/browser-registry-'.Str::uuid();
});

afterEach(function (): void {
    (new Filesystem())->deleteDirectory($this->root);
});

function registryFixture(string $root, string $name, array $overrides = []): Manifest
{
    $path = $root.'/'.$name;
    (new Filesystem())->ensureDirectoryExists($path);
    $data = $overrides + [
        'name' => $name,
        'display_name' => ucfirst($name),
        'description' => 'Registry test module',
        'version' => '1.0.0',
        'category' => 'capability',
        'provider' => ServiceProvider::class,
        'requires' => [],
        'capabilities' => [],
        'features' => [ucfirst($name).' feature'],
        'default_enabled' => true,
    ];
    file_put_contents($path.'/module.json', json_encode($data, JSON_THROW_ON_ERROR));
    file_put_contents($path.'/composer.json', json_encode(['name' => 'local/'.$name], JSON_THROW_ON_ERROR));

    return Manifest::fromFile($path.'/module.json');
}

it('preserves module selection and feature lookup across registry serialization', function (): void {
    $provider = registryFixture($this->root, 'catalog', ['default_enabled' => false]);
    $registry = new ModuleRegistry(['catalog' => $provider]);
    $restored = unserialize(serialize($registry), ['allowed_classes' => [ModuleRegistry::class, Manifest::class]]);

    expect($restored->enabled('catalog'))->toBeFalse()
        ->and($restored->enabled('catalog', ['catalog']))->toBeTrue()
        ->and($restored->providingFeature(' CATALOG FEATURE '))->toEqual([$provider])
        ->and($restored->providingFeature('Catalog'))->toBe([]);
    expect(fn () => $restored->__unserialize(['modules' => ['catalog' => 'invalid']]))
        ->toThrow(RuntimeException::class, 'The module registry cache is invalid');
});

it('orders capability providers ahead of their consumers and rejects cycles', function (): void {
    $consumer = registryFixture($this->root, 'consumer', [
        'capabilities' => ['browser.consumer'],
        'requires' => ['capabilities' => ['browser.storage' => '^1.0']],
    ]);
    $storage = registryFixture($this->root, 'storage', ['capabilities' => ['browser.storage']]);
    $registry = new ModuleRegistry(['consumer' => $consumer, 'storage' => $storage]);

    expect($registry->resolve([]))->toBe([$storage, $consumer]);
    expect(fn () => $registry->resolve([], ['storage']))
        ->toThrow(DependencyResolutionFailed::class, 'requires enabled capability [browser.storage ^1.0]');

    $cyclicStorage = registryFixture($this->root, 'storage', [
        'capabilities' => ['browser.storage'],
        'requires' => ['capabilities' => ['browser.consumer' => '^1.0']],
    ]);
    expect(fn () => (new ModuleRegistry(['consumer' => $consumer, 'storage' => $cyclicStorage]))->resolve([]))
        ->toThrow(DependencyResolutionFailed::class, 'Circular module dependency');
});

it('reports the selected module and its unsatisfied dependency', function (array $requires, string $error): void {
    $consumer = registryFixture($this->root, 'consumer', ['requires' => $requires]);
    $storage = registryFixture($this->root, 'storage');

    expect(fn () => (new ModuleRegistry(['consumer' => $consumer, 'storage' => $storage]))->resolve([]))
        ->toThrow(DependencyResolutionFailed::class, $error);
})->with([
    'missing library' => [['packages' => ['local/missing-browser-library' => '^1.0']], 'Module [consumer] requires missing or incompatible library'],
    'incompatible module version' => [['packages' => ['local/storage' => '^2.0']], 'installed manifest is 1.0.0'],
    'missing capability' => [['capabilities' => ['browser.missing' => '^1.0']], 'Module [consumer] requires missing capability [browser.missing]'],
]);

it('isolates an invalid optional module and removes its dependent from the boot set', function (): void {
    registryFixture($this->root, 'fixture-storage', [
        'provider' => 'UnavailableFixtureStorageProvider',
        'capabilities' => ['fixture.browser.storage'],
    ]);
    $consumer = registryFixture($this->root, 'fixture-consumer', [
        'requires' => ['capabilities' => ['fixture.browser.storage' => '^1.0']],
        'provider' => ItemsServiceProvider::class,
    ]);
    $package = 'local/fixture-consumer';
    file_put_contents($consumer->path.'/composer.json', json_encode([
        'name' => $package,
        'type' => 'liberu-module',
        'extra' => ['liberu' => ['name' => 'fixture-consumer']],
    ], JSON_THROW_ON_ERROR));
    $original = InstalledVersions::getRawData();
    $installed = $original;
    $installed['versions'][$package] = [
        'pretty_version' => '1.0.0',
        'version' => '1.0.0.0',
        'type' => 'liberu-module',
        'install_path' => $consumer->path,
        'aliases' => [],
        'dev_requirement' => false,
    ];
    InstalledVersions::reload($installed);
    try {
        $loader = new ModuleLoader(new ModuleValidator(), new RegistryCache(), [$this->root], [], ['module-manager'], ['module-manager']);
        $inventory = $loader->load();
    } finally {
        InstalledVersions::reload($original);
    }

    expect($inventory->isBooted('module-manager'))->toBeTrue()
        ->and($inventory->refusedDisables)->toBe(['module-manager'])
        ->and($inventory->status('fixture-storage'))->toBe('invalid')
        ->and(implode(' ', $inventory->problems['fixture-storage']))->toContain('not autoloadable')
        ->and($inventory->status('fixture-consumer'))->toBe('invalid')
        ->and(implode(' ', $inventory->problems['fixture-consumer']))->toContain('requires enabled capability');
});

it('fails loading when a required module is absent or fails validation', function (): void {
    registryFixture($this->root, 'fixture-storage', ['provider' => 'UnavailableFixtureStorageProvider']);
    $validator = new ModuleValidator();
    $cache = new RegistryCache();

    expect(fn () => (new ModuleLoader($validator, $cache, [$this->root], [], [], ['fixture-missing']))->load())
        ->toThrow(RequiredModuleFailed::class, 'Required module [fixture-missing] cannot load');
    expect(fn () => (new ModuleLoader($validator, $cache, [$this->root], [], [], ['fixture-storage']))->load())
        ->toThrow(RequiredModuleFailed::class, 'not autoloadable');
});

it('loads the cached registry and rejects invalid cached data before falling back to discovery after clearing', function (): void {
    $paths = [base_path('modules')];
    $cache = new RegistryCache();
    $cachePath = $this->root.'/nested/registry.cache';
    $registry = (new ModuleDiscovery())->discover($paths);
    $cache->write($registry, $cachePath);
    $validator = new ModuleValidator();
    $cached = new ModuleLoader($validator, $cache, $paths, [], [], [], true, $cachePath);
    $uncached = new ModuleLoader($validator, $cache, $paths, [], [], []);

    expect(array_keys($cached->load()->booted))->toBe(array_keys($uncached->load()->booted));

    file_put_contents($cachePath, serialize('invalid registry'));
    expect(fn () => $cached->load())->toThrow(RuntimeException::class, 'The module registry cache is invalid');

    $cache->clear($cachePath);
    expect(is_file($cachePath))->toBeFalse()
        ->and(array_keys($cached->load()->booted))->toBe(array_keys($uncached->load()->booted));
});

it('reports package and provider compatibility problems during a module audit', function (): void {
    $fixture = registryFixture($this->root, 'fixture-audit', [
        'version' => '99.0.0',
        'requires' => [
            'php' => '^7.4',
            'laravel' => '^9.0',
            'packages' => ['local/missing-browser-library' => '^1.0'],
        ],
        'provider' => stdClass::class,
        'presentation' => ['filament' => ['admin' => ['UnavailableFixturePanelPlugin']]],
    ]);
    file_put_contents($fixture->path.'/composer.json', json_encode([
        'name' => 'composer/semver',
        'type' => 'liberu-module',
        'extra' => ['liberu' => ['name' => 'fixture-audit']],
    ], JSON_THROW_ON_ERROR));

    $loader = new ModuleLoader(new ModuleValidator(), new RegistryCache(), [$this->root], [], [], []);
    $problems = implode("\n", $loader->audit()['fixture-audit']);

    expect($problems)->toContain(
        'installed Composer version does not match',
        'Composer and manifest Liberu dependencies differ',
        "provider must extend Laravel's ServiceProvider",
        'does not satisfy ^7.4',
        'does not satisfy ^9.0',
        'only presentation modules may declare Filament plugins',
        'presentation plugin UnavailableFixturePanelPlugin is not autoloadable',
        'requires missing or incompatible library',
    );
});

it('surfaces registry cache write and removal failures', function (): void {
    $path = $this->root.'/registry.cache';
    $files = Mockery::mock(Filesystem::class);
    $files->shouldReceive('isDirectory')->with($this->root)->andReturnTrue();
    $files->shouldReceive('put')->andReturnFalse();
    $files->shouldReceive('isFile')->with($path)->andReturnTrue();
    $files->shouldReceive('delete')->with($path)->andReturnFalse();
    $cache = new RegistryCache($files);

    expect(fn () => $cache->write(new ModuleRegistry([]), $path))->toThrow(RuntimeException::class, 'Unable to write')
        ->and(fn () => $cache->clear($path))->toThrow(RuntimeException::class, 'Unable to clear');
});

it('rejects invalid manifest metadata before the module can be selected', function (array $metadata, string $message): void {
    expect(fn () => registryFixture($this->root, 'invalid-catalog', $metadata))
        ->toThrow(InvalidManifest::class, $message);
})->with([
    'invalid name' => [['name' => 'Invalid Catalog'], 'invalid module name'],
    'invalid requires' => [['requires' => '^1.0'], 'invalid requires, capabilities, or features metadata'],
    'unknown category' => [['category' => 'other'], 'invalid category'],
    'unknown module type' => [['module_type' => 'optional'], 'module_type must be one of'],
    'ambiguous activation' => [['default_enabled' => 'false'], 'default_enabled must be boolean'],
    'invalid capability' => [['capabilities' => ['browser inventory']], 'invalid capability'],
    'missing features' => [['features' => []], 'must declare at least one feature'],
    'invalid feature' => [['features' => [' Inventory']], 'invalid feature'],
    'duplicate features' => [['features' => ['Inventory', 'INVENTORY']], 'duplicate features'],
]);

it('reports provider failures and unreadable modules alongside disabled and booted modules', function (): void {
    $core = registryFixture($this->root, 'core');
    $catalog = registryFixture($this->root, 'catalog', ['module_type' => 'feature', 'description' => 'Catalog support']);
    $disabled = registryFixture($this->root, 'disabled', ['default_enabled' => false]);
    $inventory = new ModuleInventory(
        ['core' => $core, 'catalog' => $catalog, 'disabled' => $disabled],
        ['core' => $core, 'catalog' => $catalog],
        ['corrupt' => ['Malformed manifest']],
        required: ['core'],
    );

    $inventory->markFailed('catalog', 'Provider failed during registration');
    $rows = $inventory->rows();

    expect($catalog->displayName())->toBe('Catalog')
        ->and($catalog->description())->toBe('Catalog support')
        ->and($catalog->isFeature())->toBeTrue()
        ->and($catalog->toArray()['path'])->toBe($catalog->path)
        ->and($core->isFeature())->toBeFalse()
        ->and($inventory->isBooted('catalog'))->toBeFalse()
        ->and(array_keys($rows))->toBe(['catalog', 'core', 'corrupt', 'disabled'])
        ->and($rows['catalog']['status'])->toBe('invalid')
        ->and($rows['catalog']['problems'])->toBe(['Provider failed during registration'])
        ->and($rows['core']['kind'])->toBe('required')
        ->and($rows['core']['status'])->toBe('booted')
        ->and($rows['corrupt']['version'])->toBe('?')
        ->and($rows['corrupt']['status'])->toBe('invalid')
        ->and($rows['disabled']['status'])->toBe('disabled');
});

it('rejects conflicting discoveries and preserves the first usable module', function (string $conflict, string $message): void {
    $first = registryFixture($this->root, 'fixture-a', ['capabilities' => ['fixture.catalog']]);
    $second = registryFixture($this->root, 'fixture-b', match ($conflict) {
        'name' => ['name' => 'fixture-a'],
        'capability' => ['capabilities' => ['fixture.catalog']],
        default => [],
    });
    if ($conflict === 'package') {
        copy($first->path.'/composer.json', $second->path.'/composer.json');
    }

    $discovery = new ModuleDiscovery();
    expect(fn () => $discovery->discover([$this->root]))->toThrow(InvalidManifest::class, $message);
    [$registry, $problems] = $discovery->discoverTolerant([$this->root]);

    expect($registry->get('fixture-a'))->toEqual($first)
        ->and($problems[$second->name()][0])->toContain($message);
})->with([
    'duplicate module' => ['name', 'Duplicate module [fixture-a]'],
    'duplicate capability' => ['capability', 'Duplicate capability [fixture.catalog]'],
    'duplicate Composer package' => ['package', 'missing or duplicate Composer package name'],
]);

it('reports modules with missing Composer metadata', function (bool $hasComposerFile, string $message): void {
    $fixture = registryFixture($this->root, 'fixture-invalid');

    if ($hasComposerFile) {
        file_put_contents($fixture->path.'/composer.json', '{}');
    } else {
        unlink($fixture->path.'/composer.json');
    }

    [$registry, $problems] = (new ModuleDiscovery())->discoverTolerant([$this->root]);

    expect($registry->get('fixture-invalid'))->toBeNull()
        ->and($problems['fixture-invalid'][0])->toContain($message);
})->with([
    'missing file' => [false, 'has no composer.json'],
    'missing package name' => [true, 'missing or duplicate Composer package name'],
]);

it('prefers the canonical checkout when its legacy sibling exists', function (): void {
    $canonical = registryFixture($this->root, 'liberu-fixture');
    registryFixture($this->root, 'module-liberu-fixture', ['name' => $canonical->name()]);

    $registry = (new ModuleDiscovery())->discover([$this->root]);

    expect($registry->get($canonical->name())->path)->toBe($canonical->path);
});
