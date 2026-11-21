<?php

use App\Models\Team;
use App\Models\User;
use Filament\Panel;
use Liberu\BrowserGame\AccountsFilament\AccountsFilamentPlugin;
use Liberu\BrowserGame\AccountsFilament\Resources\AccountsResource;
use Liberu\BrowserGame\AccountsFilament\Resources\AccountsResource\Pages\CreateAccount;
use Liberu\BrowserGame\CharactersFilament\CharactersFilamentPlugin;
use Liberu\BrowserGame\CharactersFilament\Resources\CharacterResource;
use Liberu\BrowserGame\CharactersFilament\Resources\CharacterResource\Pages\CreateCharacter;
use Liberu\BrowserGame\CollectionsFilament\CollectionsFilamentPlugin;
use Liberu\BrowserGame\CollectionsFilament\Resources\CollectionsResource;
use Liberu\BrowserGame\CollectionsFilament\Resources\CollectionsResource\Pages\CreateCollections;
use Liberu\BrowserGame\CombatFilament\CombatFilamentPlugin;
use Liberu\BrowserGame\CombatFilament\Resources\CombatBattleResource;
use Liberu\BrowserGame\CombatFilament\Resources\CombatBattleResource\Pages\CreateCombatBattle;
use Liberu\BrowserGame\CombatFilament\Resources\CombatDefinitionResource;
use Liberu\BrowserGame\CommerceFilament\CommerceFilamentPlugin;
use Liberu\BrowserGame\CommerceFilament\Resources\CommerceResource;
use Liberu\BrowserGame\CommerceFilament\Resources\CommerceResource\Pages\CreateCommerce;
use Liberu\BrowserGame\CompetitionFilament\CompetitionFilamentPlugin;
use Liberu\BrowserGame\CompetitionFilament\Resources\CompetitionResource;
use Liberu\BrowserGame\CompetitionFilament\Resources\CompetitionResource\Pages\CreateCompetition;
use Liberu\BrowserGame\CraftingFilament\CraftingFilamentPlugin;
use Liberu\BrowserGame\CraftingFilament\Resources\CraftingResource;
use Liberu\BrowserGame\CraftingFilament\Resources\CraftingResource\Pages\CreateCrafting;
use Liberu\BrowserGame\EconomyFilament\EconomyFilamentPlugin;
use Liberu\BrowserGame\EconomyFilament\Resources\EconomyResource;
use Liberu\BrowserGame\EconomyFilament\Resources\EconomyResource\Pages\CreateEconomy;
use Liberu\BrowserGame\ItemsFilament\ItemsFilamentPlugin;
use Liberu\BrowserGame\ItemsFilament\Resources\ItemsResource;
use Liberu\BrowserGame\ItemsFilament\Resources\ItemsResource\Pages\CreateItem;
use Liberu\BrowserGame\LiveOpsFilament\LiveOpsFilamentPlugin;
use Liberu\BrowserGame\LiveOpsFilament\Resources\LiveOpsResource;
use Liberu\BrowserGame\LiveOpsFilament\Resources\LiveOpsResource\Pages\CreateLiveOps;
use Liberu\BrowserGame\ModerationAndAnalyticsFilament\ModerationAndAnalyticsFilamentPlugin;
use Liberu\BrowserGame\ModerationAndAnalyticsFilament\Resources\ModerationAndAnalyticsResource;
use Liberu\BrowserGame\ModerationAndAnalyticsFilament\Resources\ModerationAndAnalyticsResource\Pages\CreateModerationAndAnalytics;
use Liberu\BrowserGame\QuestsFilament\QuestsFilamentPlugin;
use Liberu\BrowserGame\QuestsFilament\Resources\QuestResource;
use Liberu\BrowserGame\QuestsFilament\Resources\QuestResource\Pages\CreateQuest;
use Liberu\BrowserGame\SocialFilament\Resources\SocialResource;
use Liberu\BrowserGame\SocialFilament\Resources\SocialResource\Pages\CreateSocial;
use Liberu\BrowserGame\SocialFilament\SocialFilamentPlugin;
use Liberu\BrowserGame\WorldFilament\Resources\WorldEntityResource;
use Liberu\BrowserGame\WorldFilament\Resources\WorldEntityResource\Pages\CreateWorldEntity;
use Liberu\BrowserGame\WorldFilament\WorldFilamentPlugin;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

it('adds the active team to browser game resource creation data', function (string $pageClass): void {
    $team = new Team();
    $team->setAttribute('id', 42);
    $team->setAttribute('tenant_id', 'tenant-42');

    $user = new User();
    $user->setRelation('currentTeam', $team);
    auth()->setUser($user);

    $method = new ReflectionMethod($pageClass, 'mutateFormDataBeforeCreate');
    $result = $method->invoke(new $pageClass(), ['name' => 'Example']);

    expect($result)->toBe([
        'name' => 'Example',
        'tenant_id' => 'tenant-42',
        'team_id' => 42,
    ]);
})->with([
    'accounts' => [CreateAccount::class],
    'characters' => [CreateCharacter::class],
    'collections' => [CreateCollections::class],
    'combat battles' => [CreateCombatBattle::class],
    'commerce' => [CreateCommerce::class],
    'competitions' => [CreateCompetition::class],
    'crafting' => [CreateCrafting::class],
    'economy' => [CreateEconomy::class],
    'items' => [CreateItem::class],
    'live operations' => [CreateLiveOps::class],
    'moderation and analytics' => [CreateModerationAndAnalytics::class],
    'quests' => [CreateQuest::class],
    'social' => [CreateSocial::class],
    'world entities' => [CreateWorldEntity::class],
]);

it('rejects browser game resource creation without an active team', function (string $pageClass): void {
    auth()->setUser(new User());

    $method = new ReflectionMethod($pageClass, 'mutateFormDataBeforeCreate');

    expect(fn () => $method->invoke(new $pageClass(), ['name' => 'Example']))
        ->toThrow(HttpException::class);
})->with([
    'accounts' => [CreateAccount::class],
    'characters' => [CreateCharacter::class],
    'collections' => [CreateCollections::class],
    'combat battles' => [CreateCombatBattle::class],
    'commerce' => [CreateCommerce::class],
    'competitions' => [CreateCompetition::class],
    'crafting' => [CreateCrafting::class],
    'economy' => [CreateEconomy::class],
    'items' => [CreateItem::class],
    'live operations' => [CreateLiveOps::class],
    'moderation and analytics' => [CreateModerationAndAnalytics::class],
    'quests' => [CreateQuest::class],
    'social' => [CreateSocial::class],
    'world entities' => [CreateWorldEntity::class],
]);
