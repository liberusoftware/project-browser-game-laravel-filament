<?php

use Filament\Panel;
use Liberu\BrowserGame\AccountsFilament\AccountsFilamentPlugin;
use Liberu\BrowserGame\AccountsFilament\Resources\AccountsResource;
use Liberu\BrowserGame\CharactersFilament\CharactersFilamentPlugin;
use Liberu\BrowserGame\CharactersFilament\Resources\CharacterResource;
use Liberu\BrowserGame\CollectionsFilament\CollectionsFilamentPlugin;
use Liberu\BrowserGame\CollectionsFilament\Resources\CollectionsResource;
use Liberu\BrowserGame\CombatFilament\CombatFilamentPlugin;
use Liberu\BrowserGame\CombatFilament\Resources\CombatBattleResource;
use Liberu\BrowserGame\CombatFilament\Resources\CombatDefinitionResource;
use Liberu\BrowserGame\CommerceFilament\CommerceFilamentPlugin;
use Liberu\BrowserGame\CommerceFilament\Resources\CommerceResource;
use Liberu\BrowserGame\CompetitionFilament\CompetitionFilamentPlugin;
use Liberu\BrowserGame\CompetitionFilament\Resources\CompetitionResource;
use Liberu\BrowserGame\CraftingFilament\CraftingFilamentPlugin;
use Liberu\BrowserGame\CraftingFilament\Resources\CraftingResource;
use Liberu\BrowserGame\EconomyFilament\EconomyFilamentPlugin;
use Liberu\BrowserGame\EconomyFilament\Resources\EconomyResource;
use Liberu\BrowserGame\ItemsFilament\ItemsFilamentPlugin;
use Liberu\BrowserGame\ItemsFilament\Resources\ItemsResource;
use Liberu\BrowserGame\LiveOpsFilament\LiveOpsFilamentPlugin;
use Liberu\BrowserGame\LiveOpsFilament\Resources\LiveOpsResource;
use Liberu\BrowserGame\ModerationAndAnalyticsFilament\ModerationAndAnalyticsFilamentPlugin;
use Liberu\BrowserGame\ModerationAndAnalyticsFilament\Resources\ModerationAndAnalyticsResource;
use Liberu\BrowserGame\QuestsFilament\QuestsFilamentPlugin;
use Liberu\BrowserGame\QuestsFilament\Resources\QuestResource;
use Liberu\BrowserGame\SocialFilament\Resources\SocialResource;
use Liberu\BrowserGame\SocialFilament\SocialFilamentPlugin;
use Liberu\BrowserGame\WorldFilament\Resources\WorldEntityResource;
use Liberu\BrowserGame\WorldFilament\WorldFilamentPlugin;

it('registers every browser game Filament plugin and resource', function (string $pluginClass, string $pluginId, array $resources): void {
    $plugin = $pluginClass::make();
    $panel = Panel::make()->id('coverage');

    expect($plugin->getId())->toBe($pluginId);

    $plugin->register($panel);

    expect($panel->getResources())->toBe($resources);
})->with([
    'accounts' => [AccountsFilamentPlugin::class, 'browser-game-accounts', [AccountsResource::class]],
    'characters' => [CharactersFilamentPlugin::class, 'browser-game-characters', [CharacterResource::class]],
    'collections' => [CollectionsFilamentPlugin::class, 'browser-game-collections', [CollectionsResource::class]],
    'combat' => [CombatFilamentPlugin::class, 'browser-game-combat', [CombatBattleResource::class, CombatDefinitionResource::class]],
    'commerce' => [CommerceFilamentPlugin::class, 'browser-game-commerce', [CommerceResource::class]],
    'competition' => [CompetitionFilamentPlugin::class, 'browser-game-competition', [CompetitionResource::class]],
    'crafting' => [CraftingFilamentPlugin::class, 'browser-game-crafting', [CraftingResource::class]],
    'economy' => [EconomyFilamentPlugin::class, 'browser-game-economy', [EconomyResource::class]],
    'items' => [ItemsFilamentPlugin::class, 'browser-game-items', [ItemsResource::class]],
    'live ops' => [LiveOpsFilamentPlugin::class, 'browser-game-live-ops', [LiveOpsResource::class]],
    'moderation and analytics' => [ModerationAndAnalyticsFilamentPlugin::class, 'browser-game-moderation-and-analytics', [ModerationAndAnalyticsResource::class]],
    'quests' => [QuestsFilamentPlugin::class, 'browser-game-quests', [QuestResource::class]],
    'social' => [SocialFilamentPlugin::class, 'browser-game-social', [SocialResource::class]],
    'world' => [WorldFilamentPlugin::class, 'browser-game-world', [WorldEntityResource::class]],
]);
