#!/usr/bin/env bash
# Runs the add-on image standalone (outside Home Assistant) against the dev Home Assistant.
# DEVELOPMENT ONLY: HA_CLIMATE_DEV_ALLOW_ALL=1 disables the ingress IP restriction.
#
# Usage: ha_climate/assets-src/run-standalone.sh [data-dir]   (default: .cache/addon-data)
# Needs docker/dev/.env.dev (created by docker/dev/bootstrap-ha.sh); the token is never printed.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DATA_DIR="${1:-${ROOT}/.cache/addon-data}"
mkdir -p "${DATA_DIR}"

# shellcheck disable=SC1091
set -a && source "${ROOT}/docker/dev/.env.dev" && set +a

docker rm -f ha-climate-addon >/dev/null 2>&1 || true
docker run -d --name ha-climate-addon \
    --add-host host.docker.internal:host-gateway \
    -p 8099:8099 \
    -v "${DATA_DIR}:/data" \
    -e HA_CLIMATE_DEV_ALLOW_ALL=1 \
    -e HA_URL="http://host.docker.internal:8123" \
    -e HA_TOKEN \
    ha-climate:dev
