# Browser Game Game Core Filament

Filament 5 resources for the Game Core module. The adapter owns navigation, form/table presentation, and accessible feedback; lifecycle rules remain in the core package.

The plugin registers resources for worlds, authoritative clocks, ruleset versions, content versions, feature flags, and maintenance state. Resource queries are scoped through the visible Game Core worlds in the current tenant/team context.

<!-- module-presentation-guide:begin -->
## Presentation architecture and project hosts

- Capability/source key: `liberusoftware/module-browser-game-game-core`.
- Actual Composer identity: `liberusoftware/module-browser-game-game-core-filament`.
- [Filament capability plan](https://github.com/liberusoftware/documentation/blob/main/projects/browser-game/filament/game-core.md) · [Filament standard](https://github.com/liberusoftware/documentation/blob/main/standards/FILAMENT.md) · [Module architecture](https://github.com/liberusoftware/documentation/blob/main/architecture/MODULES.md).

The application owns panel composition, routing, session integration and deployment. This module owns its focused presentation and delegates domain behavior to reviewed public owner contracts. Keep host App aliases, duplicate records and client-only authorization outside reusable package boundaries.

Documented consumer project families:

- [Browser Game](https://github.com/liberusoftware/project-browser-game-laravel-filament): Game worlds, players and community workflows.

Other web, mobile and desktop presentations are listed in the [project catalog](https://github.com/liberusoftware/documentation/blob/main/projects/REPOSITORIES.md). Those skeletons provide starting structures; links do not establish feature parity, compatible releases or completed device/API integrations. Independent presentation packages keep their own Composer, npm or pub metadata.
<!-- module-presentation-guide:end -->
