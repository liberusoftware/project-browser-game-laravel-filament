# Browser Game Accounts Filament

This package provides the filament implementation for the Browser Game application. It provides the administrative Filament integration for the matching core module.

## Installation

```bash
composer require liberusoftware/module-browser-game-accounts-filament
```

The package requires PHP 8.5 and Laravel 13. Enable it through the host application's module composition. The filament adapter does not own domain state and must be used with the matching core package.

## License

MIT. See [LICENSE](LICENSE.md).

<!-- module-presentation-guide:begin -->
## Presentation architecture and project hosts

- Capability/source key: `liberusoftware/module-browser-game-accounts`.
- Actual Composer identity: `liberusoftware/module-browser-game-accounts-filament`.
- [Filament capability plan](https://github.com/liberusoftware/documentation/blob/main/projects/browser-game/filament/accounts.md) · [Filament standard](https://github.com/liberusoftware/documentation/blob/main/standards/FILAMENT.md) · [Module architecture](https://github.com/liberusoftware/documentation/blob/main/architecture/MODULES.md).

The application owns panel composition, routing, session integration and deployment. This module owns its focused presentation and delegates domain behavior to reviewed public owner contracts. Keep host App aliases, duplicate records and client-only authorization outside reusable package boundaries.

Documented consumer project families:

- [Browser Game](https://github.com/liberusoftware/project-browser-game-laravel-filament): Game worlds, players and community workflows.

Other web, mobile and desktop presentations are listed in the [project catalog](https://github.com/liberusoftware/documentation/blob/main/projects/REPOSITORIES.md). Those skeletons provide starting structures; links do not establish feature parity, compatible releases or completed device/API integrations. Independent presentation packages keep their own Composer, npm or pub metadata.
<!-- module-presentation-guide:end -->
