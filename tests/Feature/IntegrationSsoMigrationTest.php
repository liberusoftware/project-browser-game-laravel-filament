<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('adds a disabled SSO requirement to older connections and preserves existing opt-ins', function (): void {
    $migration = require base_path('modules/integrations/database/migrations/2026_08_31_000000_ensure_require_sso_columns.php');
    $team = Team::factory()->create();
    Schema::table('saml_connections', fn (Blueprint $table) => $table->dropColumn('require_sso'));

    DB::table('saml_connections')->insert([
        'team_id' => $team->getKey(),
        'idp_entity_id' => 'example-entity',
        'idp_sso_url' => 'https://example.test/saml',
        'idp_x509_cert' => 'fixture-certificate',
    ]);
    DB::table('sso_connections')->insert([
        'team_id' => $team->getKey(),
        'client_id' => 'example-client',
        'client_secret' => 'fixture-secret',
        'issuer_url' => 'https://example.test',
        'require_sso' => true,
    ]);

    $migration->up();
    $migration->up();
    expect((bool) DB::table('saml_connections')->value('require_sso'))->toBeFalse()
        ->and((bool) DB::table('sso_connections')->value('require_sso'))->toBeTrue();

    $migration->down();
    $migration->down();
    expect(Schema::hasColumn('saml_connections', 'require_sso'))->toBeFalse()
        ->and(Schema::hasColumn('sso_connections', 'require_sso'))->toBeFalse()
        ->and(DB::table('saml_connections')->value('idp_entity_id'))->toBe('example-entity')
        ->and(DB::table('sso_connections')->value('client_id'))->toBe('example-client');
});

it('leaves optional connection tables absent when applying or reversing the SSO backfill', function (): void {
    $migration = require base_path('modules/integrations/database/migrations/2026_08_31_000000_ensure_require_sso_columns.php');
    Schema::drop('sso_connections');
    Schema::drop('saml_connections');

    $migration->up();
    $migration->down();

    expect(Schema::hasTable('sso_connections'))->toBeFalse()
        ->and(Schema::hasTable('saml_connections'))->toBeFalse()
        ->and(Schema::hasTable('teams'))->toBeTrue();
});

it('reverses and reapplies optional identity-provider fields', function (string $migrationName, string $table, array $columns): void {
    $migration = require base_path("modules/integrations/database/migrations/{$migrationName}.php");
    expect(Schema::hasColumns($table, $columns))->toBeTrue();

    $migration->down();
    foreach ($columns as $column) {
        expect(Schema::hasColumn($table, $column))->toBeFalse();
    }
    expect(Schema::hasColumn($table, 'team_id'))->toBeTrue();

    $migration->up();
    expect(Schema::hasColumns($table, $columns))->toBeTrue();
})->with([
    'SSO provisioning' => ['2026_07_08_010000_add_jit_to_sso_connections_table', 'sso_connections', ['allow_jit', 'allowed_domain']],
    'SSO requirement' => ['2026_07_08_020000_add_require_sso_to_sso_connections_table', 'sso_connections', ['require_sso']],
    'SSO token authentication' => ['2026_07_08_050000_add_token_auth_method_to_sso_connections_table', 'sso_connections', ['token_auth_method']],
    'SAML provisioning' => ['2026_07_10_020000_add_jit_to_saml_connections_table', 'saml_connections', ['allow_jit', 'allowed_domain', 'require_sso', 'role_mappings']],
    'SAML logout URL' => ['2026_07_10_030000_add_slo_url_to_saml_connections_table', 'saml_connections', ['idp_slo_url']],
]);

it('preserves existing identity-provider schema on reapply and can recreate its base table', function (string $migrationName, string $table, string $providerColumn): void {
    $migration = require base_path("modules/integrations/database/migrations/{$migrationName}.php");
    $columns = Schema::getColumnListing($table);

    $migration->up();
    expect(Schema::getColumnListing($table))->toBe($columns);

    $migration->down();
    expect(Schema::hasTable($table))->toBeFalse();

    $migration->up();
    expect(Schema::hasColumns($table, ['team_id', $providerColumn, 'enabled']))->toBeTrue()
        ->and(Schema::hasIndex($table, ['team_id'], 'unique'))->toBeTrue();
})->with([
    'SSO connection' => ['2026_07_08_000000_create_sso_connections_table', 'sso_connections', 'issuer_url'],
    'SAML connection' => ['2026_07_08_090000_create_saml_connections_table', 'saml_connections', 'idp_sso_url'],
]);
