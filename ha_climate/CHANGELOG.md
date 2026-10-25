# Changelog

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
