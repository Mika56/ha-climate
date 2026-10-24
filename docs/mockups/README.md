# UI mockups

Reference screens for the front end (French copy, Home Assistant look, light/dark via the `hc dark` class).
They come from the design canvas "HA Climate – maquettes UI" and are written in the canvas' `.dc.html`
format: plain HTML inside `<x-dc>`, styles in `<helmet><style>`, sample data and interactions in the
`renderVals()` script at the bottom. They are references, not code to ship.

| File | Screen |
|---|---|
| Main.dc.html | Overview: season, presence, weather, vacation, alerts, live room cards with inline boost, last decisions |
| Rooms.dc.html | Room configuration: sensors, openings, devices + anti-cycling, preset temperatures, schedules, delays, preheat |
| Schedule.dc.html | Weekly schedule editor with anchored slot starts |
| Settings.dc.html | Global settings: season, presence, vacation, manual changes, presets, display (language/theme), audit retention, backup |
| Notifications.dc.html | Notification targets and per-type settings |
| History.dc.html | Audit trail with filters |
