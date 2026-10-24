# HA Climate

Work in progress: a Home Assistant add-on that drives heating and air conditioning
(event-driven, per-room settings, full audit trail).

## Development

The host only needs Docker and git. All tooling runs in containers through `make`:

| Command | Purpose |
|---|---|
| `make dev-image` | Build the PHP dev image |
| `make php-install` | `composer install` in `app/` |
| `make php-ci` | Lint, php-cs-fixer, PHPStan (max), PHPUnit with coverage gate (90%) |
| `make cs-fix` | Apply php-cs-fixer |
| `make stan` / `make test` | PHPStan / PHPUnit only |
| `make front-install` / `make front-ci` | npm ci / full frontend gate (80% coverage) |
| `make ci` | `php-ci` + `front-ci` |
| `make sh-php` | Shell in the PHP container |

### Development Home Assistant

`docker compose -f compose.dev.yaml up -d` starts a Home Assistant Core instance on
http://localhost:8123 with fake entities (windows, presence, temperatures, a generic
thermostat) defined in `docker/dev/ha-config/configuration.yaml`.

To create a long-lived access token: open your profile page
(http://localhost:8123/profile/security), scroll to "Long-lived access tokens", click
"Create token" and copy it. Export it as `HA_TOKEN` together with
`HA_URL=http://localhost:8123` to run the engine in standalone mode.

## License

MIT
