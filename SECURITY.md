# Security policy

## Supported versions

Only the latest release receives security fixes.

## Reporting a vulnerability

Please do **not** open a public issue. Use GitHub's private vulnerability reporting instead:
<https://github.com/OWNER/ha-climate/security/advisories/new> (repository **Security** tab › **Report a
vulnerability**).

Include the add-on version, what you observed, and steps to reproduce. You can expect an acknowledgement within a few
days; a fix and a coordinated disclosure follow depending on severity.

## Scope notes

The add-on is only reachable through Home Assistant ingress (the Supervisor authenticates users and nginx accepts
only the Supervisor proxy). The internal engine token and application secret are generated at first start and stored
in the add-on's private `/data` volume. `HA_CLIMATE_DEV_ALLOW_ALL` exists for local development only and must never
be set on a real installation.
