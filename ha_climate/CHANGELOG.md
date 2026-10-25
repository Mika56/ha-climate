# Changelog

## 0.2.1

- Overview: the top row (HA Climate switch, season, presence, weather, vacation) fits on one line on a computer,
  with shorter texts; the link to the full history is gone (the History tab stays).
- Room cards show the **active setting** of the devices as Home Assistant reports it ("Active setting: Heat
  20.0 °C"), also while HA Climate is paused.
- A device that does not report `hvac_action` but is in an active HVAC mode is shown as "On" (it used to show
  "Idle") and counts in the "x of y heating" summary.
- Room editor: a help bubble next to "Windows and doors" lists the supported sensor types (a sensor without a
  device class, such as a group, is not listed: set "Show as" in Home Assistant).

## 0.2.0

- Behaviour change: the season is now **Winter or Summer** only. The old "Off" position is replaced by a separate
  **"HA Climate active / paused"** switch (Overview and Settings).
- While paused, HA Climate **controls nothing**: no command on your heaters or air conditioners, no notification,
  no Home Assistant persistent notification, no anti-cycling postponement, and a manual change on a device is no
  longer turned into an override (you are in charge). Boosts are refused (409 `paused`).
- While paused it keeps **observing and deciding** (rules, timers, alerts shown in the interface) and the history
  keeps running: every decision change is logged as what HA Climate *would* have done, marked as not applied
  ("Paused: HA Climate would have turned the heating off (nobody home). Nothing was sent.").
  Room cards show a "Simulation" chip.
- Pausing and resuming are recorded in the history (with the Home Assistant user). Pausing dismisses the persistent
  notifications HA Climate created; resuming evaluates every room at once (commands are sent, anti-cycling
  applies) and sends the notifications held back for alerts opened while paused.
- New `PUT /api/pause` endpoint; `paused` in the settings, the overview and the room statuses.
- Backups are version 2 (they carry `paused`); version 1 backups are still restored (season "off" becomes winter,
  paused).
- **Migration**: an installation whose season was "Off" becomes **Winter + paused** (nothing starts controlling
  your devices by surprise: switch HA Climate on when you are ready). Any other installation is unchanged and
  active. Going back to 0.1.x turns a paused installation into season "Off".

## 0.1.2

- Fix: the Schedules page stayed on "Loading…" when no schedule existed; it now offers to create the first one
  (and says so when a schedule link points to a deleted schedule).
- Fix: long notification service names were squeezed letter by letter in the Notifications targets list.

## 0.1.1

- Fix: the live room updates were cut every ~15 seconds behind the Home Assistant ingress (the heartbeat was
  slower than the internal server's idle timeout); it is now sent every 10 seconds.
- Quieter logs: the internal web-to-engine calls are only logged when something goes wrong.

## 0.1.0

- First release: room-by-room heating and air-conditioning scheduler with weekly schedules, presets, pre-heating,
  window, presence and vacation handling, manual overrides and boosts, anti short-cycling, alerts and history.
- Ingress web interface (English and French, light and dark).
