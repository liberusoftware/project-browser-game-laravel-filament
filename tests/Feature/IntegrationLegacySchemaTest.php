<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('rolls back and recreates the legacy integration tables without dropping host users', function (string $migration, array $tables): void {
    $options = [
        '--path' => base_path("modules/integrations/database/migrations/{$migration}.php"),
        '--realpath' => true,
    ];
    $batch = DB::table('migrations')->where('migration', $migration)->value('batch');
    expect($batch)->toBeInt();

    $this->artisan('migrate:rollback', $options + ['--batch' => $batch])->assertSuccessful();
    foreach ($tables as $table => $columns) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
    expect(Schema::hasTable('users'))->toBeTrue();

    $this->artisan('migrate', $options)->assertSuccessful();
    foreach ($tables as $table => $columns) {
        expect(Schema::hasColumns($table, $columns))->toBeTrue();
    }
})->with([
    'call settings' => ['2023_05_20_000000_create_call_settings_table', ['call_settings' => ['name', 'value']]],
    'accounting connections' => ['2024_10_13_000000_create_accounting_integrations_table', ['accounting_integrations' => ['user_id', 'platform', 'connection_details']]],
    'WhatsApp numbers' => ['2024_10_22_000000_create_whatsapp_numbers_table', ['whats_app_numbers' => ['number', 'display_name', 'is_active']]],
    'webhooks and deliveries' => ['2026_10_25_000001_create_legacy_webhook_tables', [
        'webhooks' => ['url', 'events'],
        'crm_webhook_deliveries' => ['webhook_id', 'success'],
    ]],
]);
