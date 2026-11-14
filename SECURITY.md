# Security Policy

## Reporting a Vulnerability

Private vulnerability reporting is enabled for [liberusoftware/project-browser-game-laravel-filament](https://github.com/liberusoftware/project-browser-game-laravel-filament).

[**Report a vulnerability privately**](https://github.com/liberusoftware/project-browser-game-laravel-filament/security/advisories/new).

Sign in to GitHub and use the link above, or open **Security → Advisories → Report a vulnerability** in this repository. Reports are handled privately through GitHub Security Advisories; maintainers can invite collaborators when needed to investigate and prepare a fix.

**Do not disclose suspected vulnerabilities in public issues, pull requests, or discussions.**

Include the affected release or commit, package versions, relevant deployment configuration, reproduction steps or a minimal proof of concept, and the security impact. Redact credentials, tokens, personal data, and other sensitive information. Test only systems you own or have permission to assess.

## What to Expect

- This project is **volunteer-maintained**; there is no guaranteed response time or SLA.
- We will investigate all legitimate reports and do our best to address confirmed vulnerabilities promptly.
- You will be credited in the security advisory unless you prefer to remain anonymous.

## Scope

The following are in scope for security reports:

- `app/` — Application code (models, controllers, services, etc.)
- `routes/` — Route definitions and middleware
- `database/` — Migrations, seeders, and factories
- `config/` — Configuration files
- `resources/` — Views and frontend assets
- API endpoints and authentication mechanisms
- WebSocket and real-time game communication

First-party modules shipped with this application, including code under `modules/`, are also in scope.

## Out of Scope

The following are **not** in scope:

- Vulnerabilities confined to third-party dependencies should be reported upstream. First-party integration flaws and application-specific impact remain in scope here.
- Issues requiring `APP_DEBUG=true` in production
- Social engineering or physical attacks
- Denial of service attacks against infrastructure
- Vulnerabilities in outdated versions that are no longer supported

## Supported Versions

Security fixes target the default branch (`main`) and the latest release, when a release is available. Older versions may require an upgrade; backports are not guaranteed. Include the affected version or commit in your report even if you are not running the latest release.
