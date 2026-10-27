# HA Climate

HA Climate drives the heating and air-conditioning devices of your Home Assistant installation **room by
room**. You describe each room (temperature sensor, presence signals, windows, climate devices), give it a weekly
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

1. **Settings**: choose the current **season** (winter = heating, summer = cooling), check that HA Climate is
   **active** (see [Pause](#pause)), review the presets (comfort, eco, night, away...) and the notification options.
2. **Schedules**: create a weekly schedule. A slot starts at a fixed time or relative to a Home Assistant entity
   (for example "bedtime"), and applies a preset.
3. **Rooms**: add a room, pick its temperature sensor, optional presence signals (see [Presence signals](#presence-signals)), window/door contacts and its
   climate devices (heating, cooling or reversible), set the temperature of each preset, then assign the schedule.
4. **Overview**: each room shows its current temperature, what the engine decided and *why* (the reason is always
   displayed). You can apply a temporary boost or manual override from there.

## How decisions are made

For every room the engine evaluates a list of rules, **highest priority first; the first rule that has an opinion
wins**, and its reason is shown in the interface:

| Priority | Rule                    | Meaning                                                                |
| -------: | ----------------------- | ---------------------------------------------------------------------- |
|      900 | No device for the mode  | The room has no device able to heat (or cool) in the current mode.     |
|      800 | Vacation                | A vacation period is active: the away preset applies.                  |
|      700 | House empty             | Nobody is home (all tracked persons away long enough).               |
|      600 | Opening                 | A window or door has been open longer than the cut delay: devices off. |
|      500 | Override                | A manual change or boost is in force.                                  |
|      400 | Room vacancy            | The room has been empty longer than its vacancy delay.                 |
|     -900 | Pre-heating             | A warmer slot is coming: start early enough to reach it on time.       |
|    -1000 | Schedule                | The slot currently in force in the room's weekly schedule.             |

Devices are protected against short cycling (minimum on/off times) and use a hysteresis around the target. If a
temperature sensor or device becomes unavailable, the room is removed from control and an alert is raised
(including a Home Assistant persistent notification). Presence signals are not part of this: an unavailable
signal is simply ignored.

## Presence signals

A room can have up to 20 **presence signals**: entities that tell that somebody is in the room. The room is
**occupied** while one of its signals is active, and **vacant** once none is, from the most recent change of
its signals; the *room vacancy* rule then applies after the vacancy delay. Three kinds exist:

| Kind    | Active while...                                            | Typical entity                                         |
| ------- | ---------------------------------------------------------- | ------------------------------------------------------ |
| Active  | the entity is `on` (`playing` or `paused` for a player)    | motion sensor, light, switch, fan, media player        |
| Above   | the numeric state is strictly above a threshold            | a power sensor (W): TV, computer, console              |
| Equals  | the state is exactly a given value                         | a Bermuda / ESPresense room sensor (value = the room)  |

Good to know:

- A signal whose entity is missing, `unavailable` or `unknown` is **ignored**. When every signal of a room is
  ignored, or when the room has none, the room is never considered vacant (it keeps following its schedule).
- A **light left on keeps the room occupied**: only add lights and switches you really switch off when you
  leave.
- A signal counts as inactive **from the moment it stopped being active** (the power dropped under the
  threshold, the room sensor left the room), not from its last change: a standby power that keeps wobbling does
  not keep the room occupied. After a restart of HA Climate, the last change of the entity is used instead.
- Rooms configured before this feature keep their presence sensor as one *Active* signal.

## Pause

The **HA Climate active** switch (on the Overview and in Settings) pauses HA Climate without losing anything.
While it is paused, **HA Climate controls nothing**, so you can use your heaters and air conditioners freely:

- no command is sent to any device, no `notify` notification and no persistent notification is created;
- no anti-cycling postponement and no "device not responding" watch: nothing is commanded;
- a change you make on a device is not turned into a manual override, and boosts are unavailable;
- the overview shows a banner with a **Resume** button, and each room card a **Simulation** chip with the target it
  *would* apply.

HA Climate keeps **observing and deciding** with the current season: rules, timers and alerts keep working (alerts
are displayed but not notified), and the **History** keeps recording every decision change as what HA Climate
*would* have done, clearly marked as not applied ("Paused: HA Climate would have turned the heating off (nobody
home). Nothing was sent."). Pausing and resuming are themselves recorded, with the Home Assistant user.

- **Pausing** dismisses the Home Assistant persistent notifications HA Climate created (the alerts stay visible in
  the interface). Boosts and manual overrides that were running keep their timers, but are ignored while paused.
- **Resuming** re-evaluates every room at once: commands are sent normally (anti-cycling applies), alerts opened
  while paused and never notified are notified (respecting the notification type settings) and their persistent
  notifications are recreated.

Versions before 0.2.0 had an "Off" season for this; an installation that was "Off" is now "Winter" and paused.

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
- **Nothing happens, and the overview shows a banner**: HA Climate is paused (see [Pause](#pause)); press **Resume**.
- **Need more detail**: set `log_level` to `debug` and restart; logs are JSON lines on the add-on Log tab.
- A Docker health check probes `/api/health`; turn on **Watchdog** on the add-on page to have it restarted automatically when it stops answering.

## Privacy

All data stays on your Home Assistant host. The add-on only communicates with Home Assistant (via the Supervisor
proxy), sends no telemetry and makes no external request. The web interface is reachable only through Home
Assistant's ingress.
