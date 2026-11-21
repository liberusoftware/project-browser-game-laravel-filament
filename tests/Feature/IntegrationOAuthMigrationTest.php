<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('reverses the OAuth token migration without changing existing configurations', function (): void {
    $migration = require base_path('modules/integrations/database/migrations/2026_08_30_000002_add_oauth_tokens_to_configurations.php');
    $columns = ['access_token', 'refresh_token', 'token_expires_at'];
    $table = 'oauth_configurations';
    $id = DB::table($table)->insertGetId([
        'service_name' => 'example-provider',
        'client_id' => 'test-client',
        'client_secret' => 'test-secret',
    ]);

    $migration->down();
    foreach ($columns as $column) {
        expect(Schema::hasColumn($table, $column))->toBeFalse();
    }
    expect(DB::table($table)->find($id)->client_id)->toBe('test-client');

    $migration->up();
    expect(Schema::hasColumns($table, $columns))->toBeTrue()
        ->and(DB::table($table)->find($id)->access_token)->toBeNull()
        ->and(DB::table($table)->find($id)->client_secret)->toBe('test-secret');
});

it('reverses optional OAuth account metadata without deleting the provider configuration', function (): void {
    $migration = require base_path('modules/integrations/database/migrations/2026_02_16_000000_add_fields_to_oauth_configurations_table.php');
    $table = 'oauth_configurations';
    $id = DB::table($table)->insertGetId([
        'service_name' => 'example-provider',
        'client_id' => 'test-client',
        'account_name' => 'Existing Account',
        'is_active' => false,
    ]);

    $migration->down();
    expect(Schema::hasColumn($table, 'account_name'))->toBeFalse()
        ->and(Schema::hasColumn($table, 'is_active'))->toBeFalse()
        ->and(DB::table($table)->find($id)->service_name)->toBe('example-provider');

    $migration->up();
    expect(Schema::hasColumns($table, ['account_name', 'is_active']))->toBeTrue()
        ->and(DB::table($table)->find($id)->account_name)->toBeNull()
        ->and((bool) DB::table($table)->find($id)->is_active)->toBeTrue();
});

it('widens the OAuth secret column and accepts pending configurations without a secret', function (): void {
    $migration = require base_path('modules/integrations/database/migrations/2026_07_08_000001_encrypt_oauth_configuration_client_secret.php');
    $table = 'oauth_configurations';
    $id = DB::table($table)->insertGetId([
        'service_name' => 'existing-provider',
        'client_id' => 'test-client',
        'client_secret' => 'existing-value',
    ]);

    $migration->down();
    expect(collect(Schema::getColumns($table))->firstWhere('name', 'client_secret')['nullable'])->toBeFalse();

    $migration->up();
    $longSecret = str_repeat('ciphertext-fixture', 25);
    DB::table($table)->where('id', $id)->update(['client_secret' => $longSecret]);
    $pendingId = DB::table($table)->insertGetId([
        'service_name' => 'pending-provider',
        'client_id' => 'pending-client',
    ]);

    expect(collect(Schema::getColumns($table))->firstWhere('name', 'client_secret')['type_name'])->toBe('text')
        ->and(DB::table($table)->find($id)->client_secret)->toBe($longSecret)
        ->and(DB::table($table)->find($pendingId)->client_secret)->toBeNull();
});

it('recreates the integration connections table with a unique provider per scope', function (): void {
    $migration = require base_path('modules/integrations/database/migrations/2026_08_01_030100_create_integration_connections_table.php');
    $table = 'integration_connections';

    $migration->down();
    expect(Schema::hasTable($table))->toBeFalse()
        ->and(Schema::hasTable('oauth_configurations'))->toBeTrue();

    $migration->up();
    expect(Schema::hasColumns($table, ['credentials', 'capabilities', 'status']))->toBeTrue()
        ->and(Schema::hasIndex($table, ['scope_type', 'scope_id', 'provider'], 'unique'))->toBeTrue();
});
