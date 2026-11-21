<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Liberu\BrowserGame\Crafting\Models\CraftingResource;
use Liberu\BrowserGame\Crafting\Support\CraftingManager;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->artisan('migrate', [
        '--path' => base_path('modules/browser-game-crafting/database/migrations'),
        '--realpath' => true,
    ]);
});

it('reverses crafting scope and restores the scoped recipe discovery index on reapply', function (): void {
    $options = ['--path' => base_path('modules/browser-game-crafting/database/migrations'), '--realpath' => true];
    $discoveries = 'browser_game_crafting_discoveries';

    $this->artisan('migrate:rollback', $options + ['--step' => 1])->assertSuccessful();
    expect(Schema::hasColumns($discoveries, ['tenant_id', 'team_id']))->toBeFalse()
        ->and(Schema::hasIndex($discoveries, ['actor_id', 'recipe_id'], 'unique'))->toBeTrue();

    $this->artisan('migrate:rollback', $options + ['--step' => 1])->assertSuccessful();
    foreach (['browser_game_crafting', 'browser_game_crafting_resources', 'browser_game_crafting_professions', 'browser_game_crafting_queues', $discoveries, 'browser_game_crafting_outputs'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
    expect(Schema::hasTable('users'))->toBeTrue();

    $this->artisan('migrate', $options)->assertSuccessful();
    expect(Schema::hasIndex($discoveries, ['actor_id', 'recipe_id', 'tenant_id', 'team_id'], 'unique'))->toBeTrue();
});

it('consumes materials once for an idempotent queue and does not duplicate completion outputs', function (): void {
    $manager = app(CraftingManager::class);
    $recipe = $manager->define('Reliable Sword', [
        'materials' => ['iron' => 2],
        'outputs' => ['sword' => 1],
        'crafting_time_seconds' => 0,
        'success_rate' => 100,
    ]);
    $manager->grantResource('player-1', 'iron', 2);

    $queue = $manager->queueCraft('player-1', $recipe, 1, 90, 'queue-operation-1');
    $retry = $manager->queueCraft('player-1', $recipe, 1, 90, 'queue-operation-1');
    $completed = $manager->complete($queue);
    $again = $manager->complete($completed);

    expect($retry->getKey())->toBe($queue->getKey())
        ->and(CraftingResource::query()->where('actor_id', 'player-1')->where('resource_key', 'iron')->exists())->toBeFalse()
        ->and($completed->outputs)->toHaveCount(1)
        ->and($again->outputs)->toHaveCount(1);
});

it('refunds cancellation once and salvages a terminal queue once', function (): void {
    $manager = app(CraftingManager::class);
    $recipe = $manager->define('Recoverable Potion', [
        'materials' => ['herb' => 2],
        'outputs' => ['potion' => 1],
        'salvage' => ['herb' => 1],
        'crafting_time_seconds' => 0,
        'success_rate' => 100,
    ]);
    $manager->grantResource('player-2', 'herb', 2);
    $queue = $manager->queueCraft('player-2', $recipe);
    $manager->cancel($queue);
    $manager->cancel($queue);

    expect(CraftingResource::query()->where('actor_id', 'player-2')->where('resource_key', 'herb')->value('quantity'))->toBe(2);

    $manager->grantResource('player-2', 'herb', 2);
    $failedRecipe = $manager->define('Failed Potion', ['materials' => ['herb' => 1], 'salvage' => ['herb' => 1], 'success_rate' => 0]);
    $failed = $manager->complete($manager->queueCraft('player-2', $failedRecipe));
    $manager->salvage($failed);
    $manager->salvage($failed);

    expect(CraftingResource::query()->where('actor_id', 'player-2')->where('resource_key', 'herb')->value('quantity'))->toBe(4);
    expect(fn (): mixed => $manager->queueCraft('player-2', $recipe, 99))->toThrow(ValidationException::class);
});

it('keeps player crafting state and idempotency scoped by team', function (): void {
    $manager = app(CraftingManager::class);
    $teamOneRecipe = $manager->define('Team One Recipe', ['materials' => ['ore' => 1]], teamId: 'team-1');
    $teamTwoRecipe = $manager->define('Team Two Recipe', ['materials' => ['ore' => 1]], teamId: 'team-2');
    $manager->grantResource('player-1', 'ore', 1, teamId: 'team-1');
    $manager->grantResource('player-1', 'ore', 1, teamId: 'team-2');

    $first = $manager->queueCraft('player-1', $teamOneRecipe, idempotencyKey: 'scoped-queue', teamId: 'team-1');
    $second = $manager->queueCraft('player-1', $teamTwoRecipe, idempotencyKey: 'scoped-queue', teamId: 'team-2');

    expect($second->getKey())->not->toBe($first->getKey())
        ->and(CraftingResource::query()->where('actor_id', 'player-1')->where('team_id', 'team-1')->exists())->toBeFalse()
        ->and(CraftingResource::query()->where('actor_id', 'player-1')->where('team_id', 'team-2')->exists())->toBeFalse();
});

it('requires scoped discovery and sufficient level before completing a timed recipe', function (): void {
    $this->freezeTime();
    $manager = app(CraftingManager::class);
    $recipe = $manager->define('Timed Sword', [
        'min_level' => 2,
        'discovery_requirements' => ['required' => true],
        'crafting_time_seconds' => 60,
        'outputs' => [['item' => 'sword', 'quantity' => 2]],
    ], teamId: 'team-1');

    expect(fn () => $manager->discover('player-1', $recipe, teamId: 'team-2'))->toThrow(ValidationException::class)
        ->and(fn () => $manager->queueCraft('player-1', $recipe, actorLevel: 2, teamId: 'team-1'))->toThrow(ValidationException::class);
    expect(fn () => $manager->queueCraft('player-1', $recipe, quantity: 1001, actorLevel: 2, teamId: 'team-1'))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('quantity'));

    $discovery = $manager->discover('player-1', $recipe, teamId: 'team-1');
    expect($discovery->recipe->is($recipe))->toBeTrue()
        ->and($manager->discover('player-1', $recipe, teamId: 'team-1')->is($discovery))->toBeTrue()
        ->and(fn () => $manager->queueCraft('player-1', $recipe, teamId: 'team-1'))->toThrow(ValidationException::class);

    $queue = $manager->queueCraft('player-1', $recipe, quantity: 2, actorLevel: 2, teamId: 'team-1');
    expect(fn () => $manager->complete($queue))->toThrow(ValidationException::class)
        ->and(fn () => $manager->salvage($queue))->toThrow(ValidationException::class)
        ->and($queue->refresh()->status)->toBe('queued')
        ->and($recipe->queues()->sole()->is($queue))->toBeTrue();

    $this->travel(1)->minute();
    $output = $manager->complete($queue)->outputs->sole();
    expect($output->queue->is($queue))->toBeTrue()
        ->and($output->output_key)->toBe('sword')
        ->and($output->quantity)->toBe(4);
});

it('sets profession progress and levels up at the experience threshold', function (): void {
    $manager = app(CraftingManager::class);
    $first = $manager->gainProfessionExperience('player-1', 'smith', 99);
    $second = $manager->gainProfessionExperience('player-1', 'smith', 1);
    $updated = $manager->setProfession('player-1', 'smith', 3, 250);

    expect($first->level)->toBe(1)
        ->and($second->level)->toBe(2)
        ->and($second->experience)->toBe(100)
        ->and($updated->is($first))->toBeTrue()
        ->and($updated->experience)->toBe(250);
});

it('rejects malformed crafting inputs', function (string $operation, array $arguments, string $field): void {
    $manager = app(CraftingManager::class);
    expect(fn () => $manager->{$operation}(...$arguments))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey($field));
})->with([
    'blank recipe name' => ['define', [' '], 'name'],
    'nonpositive materials' => ['define', ['Recipe', ['materials' => ['ore' => 0]]], 'materials'],
    'out-of-range success rate' => ['define', ['Recipe', ['success_rate' => 101]], 'quality'],
    'nonpositive resource grant' => ['grantResource', ['player-1', 'ore', 0], 'quantity'],
    'invalid profession level' => ['setProfession', ['player-1', 'smith', 0], 'profession'],
    'negative experience' => ['gainProfessionExperience', ['player-1', 'smith', -1], 'experience'],
]);
