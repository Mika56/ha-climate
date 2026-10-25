# HA Climate

[![CI](https://github.com/Mika56/ha-climate/actions/workflows/ci.yml/badge.svg)](https://github.com/Mika56/ha-climate/actions/workflows/ci.yml)
[![Release](https://img.shields.io/github/v/release/Mika56/ha-climate)](https://github.com/Mika56/ha-climate/releases)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

A Home Assistant add-on that drives the heating and air conditioning of your home, room by room. It controls the
`climate` entities you already have, decides on events (not on a polling timer), and keeps a full audit trail of
every decision.

[![Add this repository to your Home Assistant instance.](https://my.home-assistant.io/badges/supervisor_add_addon_repository.svg)](https://my.home-assistant.io/redirect/supervisor_add_addon_repository/?repository_url=https%3A%2F%2Fgithub.com%2FMika56%2Fha-climate)

## Features

- **Heating and cooling with the same rules**: one scheduler for both modes, per-room settings.
- **Per-room weekly schedules** with *anchored slots* (a slot can follow a time or an event such as "when I get up").
- **Presence, vacancy and vacation** handling: rooms and the whole home can be set back automatically.
- **Windows**: opening a window pauses the room after a delay and resumes it when closed.
- **Boost**: temporary comfort for a chosen duration, back to the schedule afterwards.
- **Manual changes are respected**: a change made on the thermostat is detected and kept until the next slot.
- **Pre-heating**: learns how long each room takes to warm up and starts early enough to reach the target on time.
- **Anti short-cycling**: minimum on/off times protect your equipment.
- **Alerts and notifications**: unavailable sensors or thermostats raise an alert and a Home Assistant notification.
- **Audit**: every action is recorded with its reason, searchable and exportable.
- **Backup and restore**: export and import the whole configuration; the add-on is included in Home Assistant backups.
- **English and French** interface that follows the Home Assistant look, dark mode included.

## Screenshots

_Screenshots will be added with the first release._

## Installation

Requires Home Assistant OS or Supervised (add-ons are not available in Container or Core installs).

1. Click the button below, or add the repository manually (next step):

   [![Add this repository to your Home Assistant instance.](https://my.home-assistant.io/badges/supervisor_add_addon_repository.svg)](https://my.home-assistant.io/redirect/supervisor_add_addon_repository/?repository_url=https%3A%2F%2Fgithub.com%2FMika56%2Fha-climate)

2. Manually: **Settings › Add-ons › Add-on store › ⋮ (top right) › Repositories**, paste
   `https://github.com/Mika56/ha-climate` and click **Add**.
3. Reload the store, open **HA Climate**, click **Install**, then **Start**. Enable **Show in sidebar** to get the
   *Climate* panel.

Supported architectures: `amd64` and `aarch64` (prebuilt images on GitHub Container Registry).

See [ha_climate/DOCS.md](ha_climate/DOCS.md) for the user documentation and
[ha_climate/CHANGELOG.md](ha_climate/CHANGELOG.md) for the release notes.

## Development

The host only needs Docker and git; all tooling runs in containers through `make`:

| Command | Purpose |
|---|---|
| `make dev-image` | Build the PHP dev image |
| `make php-install` | `composer install` in `app/` |
| `make php-ci` | Lint, php-cs-fixer, PHPStan (max), PHPUnit with coverage gate (90%) |
| `make cs-fix` | Apply php-cs-fixer |
| `make stan` / `make test` | PHPStan / PHPUnit only |
| `make front-install` / `make front-ci` | `npm ci` / full frontend gate |
| `make ci` | `php-ci` + `front-ci` |
| `make sh-php` / `make sh-node` | Shell in the PHP / Node container |

Repository layout: `app/` (PHP 8.4, Symfony 7.4 engine and API), `frontend/` (Vue), `api/` (OpenAPI contract),
`ha_climate/` (add-on: Dockerfile, config, docs), `docker/dev/` (development tooling), `docs/` (packaging and release
notes).

### Development Home Assistant

```bash
docker/dev/bootstrap-ha.sh      # starts compose.dev.yaml (Home Assistant on :8123, fake entities) and creates a token
docker compose -f compose.dev.yaml down
```

The token and test user are written to `docker/dev/.env.dev` (git-ignored). To run the add-on image against it:
`ha_climate/assets-src/run-standalone.sh` (see [docs/packaging.md](docs/packaging.md)).

### Front end without a backend

```bash
cd frontend && npm ci && npm run dev:mock    # mocked API on http://localhost:5173
```

### Quality gates

- PHP: php-cs-fixer (`@Symfony`), PHPStan at level max, PHPUnit with a mandatory `#[CoversClass]` on every test,
  line coverage above 90 %.
- Front end: ESLint, Prettier, `vue-tsc`, Vitest with a coverage threshold.
- API: the contract in `api/openapi.yaml` is linted with Redocly; `frontend/src/api/schema.d.ts` is generated from it
  (`npm run api:types`) and CI fails when it is stale.
- Add-on: `frenck/action-addon-linter`, hadolint, ShellCheck, and a container smoke test.

See [CONTRIBUTING.md](CONTRIBUTING.md). Releases: [docs/releasing.md](docs/releasing.md).

## License

[MIT](LICENSE)
