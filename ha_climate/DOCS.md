# HA Climate

HA Climate drives the heating and air-conditioning devices of your Home Assistant installation **room by
room**. You describe each room (temperature sensor, presence sensor, windows, climate devices), give it a weekly
schedule, and the add-on continuously decides, for every room, whether to heat, cool or stay off, and at which
target temperature. It talks to Home Assistant only (through the Supervisor); it makes **no external call**.

## Installation

1. In Home Assistant, open **Settings > Add-ons > Add-on store**.
2. Open the menu (three dots, top right) > **Repositories** and add the repository URL:
   `https://github.com/Mika56/ha-climate`
3. Install **HA Climate**, then **Start** it. Enable **Show in sidebar** to get the **Climate** panel.
4. Open the panel (or **Open Web UI**): the interface is served through Home Assistant's ingress, so it uses
   your Home Assistant login and needs no extra port or password.

The add-on is currently flagged *experimental*.

## Add-on options

| Option      | Default | Description                                                                         |
| ----------- | ------- | ----------------------------------------------------------------------------------- |
| `log_level` | `info`  | Verbosity of the add-on log (`trace`, `debug`, `info`, `notice`, `warning`, `error`, `fatal`). |

Everything else is configured in the add-on's own interface. Restart the add-on after changing the log level.

## First configuration walkthrough

1. **Settings**: choose the current **season** (winter = heating, summer = cooling, off), review the presets
   (comfort, eco, night, away...) and the notification options.
2. **Schedules**: create a weekly schedule. A slot starts at a fixed time or relative to a Home Assistant entity
   (for example "bedtime"), and applies a preset.
3. **Rooms**: add a room, pick its temperature sensor, optional presence sensor, window/door contacts and its
   climate devices (heating, cooling or reversible), set the temperature of each preset, then assign the schedule.
4. **Overview**: each room shows its current temperature, what the engine decided and *why* (the reason is always
   displayed). You can apply a temporary boost or manual override from there.

## How decisions are made

For every room the engine evaluates a list of rules, **highest priority first; the first rule that has an opinion
wins**, and its reason is shown in the interface:

| Priority | Rule                    | Meaning                                                                |
| -------: | ----------------------- | ---------------------------------------------------------------------- |
|     1000 | Season off              | The season is "off": nothing runs.                                     |
|      900 | No device for the mode  | The room has no device able to heat (or cool) in the current mode.     |
|      800 | Vacation                | A vacation period is active: the away preset applies.                  |
|      700 | House empty             | Nobody is home (all presence sensors empty long enough).               |
|      600 | Opening                 | A window or door has been open longer than the cut delay: devices off. |
|      500 | Override                | A manual change or boost is in force.                                  |
|      400 | Room vacancy            | The room has been empty longer than its vacancy delay.                 |
|     -900 | Pre-heating             | A warmer slot is coming: start early enough to reach it on time.       |
|    -1000 | Schedule                | The slot currently in force in the room's weekly schedule.             |

Devices are protected against short cycling (minimum on/off times) and use a hysteresis around the target. If a
sensor or device becomes unavailable, the room is removed from control and an alert is raised (including a
Home Assistant persistent notification).

## Language and theme

The interface follows your Home Assistant profile: language (English or French) and light/dark theme are detected
from Home Assistant when the panel is embedded. Without it (for example opened directly), the browser language and
`prefers-color-scheme` are used. You can force a language and a theme in **Settings**; that choice is stored on
the server.

## Backups

The add-on stores everything (rooms, schedules, history, secrets) in `/data`, which is included in Home Assistant
backups (`hot` backup: the add-on keeps running; the database is consistent, SQLite being used in WAL mode).
Restoring a Home Assistant backup restores the add-on's data. The **Settings** page also offers a JSON **export**
and **import** of your configuration, useful to move to another installation.

## Troubleshooting

- **The panel is empty or shows "engine not connected"**: check the add-on **Log** tab. The engine reconnects to
  Home Assistant by itself and is restarted automatically if it stops.
- **A room is "not controlled" with an alert**: its temperature sensor or a device is `unavailable`; the room
  resumes by itself when the entity is back.
- **Nothing happens when the season is "off"**: this is intended, change the season in **Settings**.
- **Need more detail**: set `log_level` to `debug` and restart; logs are JSON lines on the add-on Log tab.
- A Docker health check probes `/api/health`; turn on **Watchdog** on the add-on page to have it restarted automatically when it stops answering.

## Privacy

All data stays on your Home Assistant host. The add-on only communicates with Home Assistant (via the Supervisor
proxy), sends no telemetry and makes no external request. The web interface is reachable only through Home
Assistant's ingress.
