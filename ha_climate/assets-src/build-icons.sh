#!/usr/bin/env bash
# Regenerates ha_climate/icon.png (128x128) and ha_climate/logo.png (250x100) from the SVG sources
# next to this script. Needs only Docker (ImageMagick + librsvg run in an Alpine container).
set -euo pipefail

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT="$(dirname "${SRC}")"

docker run --rm -v "${SRC}:/src:ro" -v "${OUT}:/out" alpine:3.24 sh -ec '
    apk add --no-cache imagemagick imagemagick-svg librsvg font-dejavu >/dev/null
    magick -background none /src/icon.svg -resize 128x128 -depth 8 -strip /out/icon.png
    magick -background none /src/logo.svg -resize 250x100 -depth 8 -strip /out/logo.png
    chown "$(stat -c %u:%g /out)" /out/icon.png /out/logo.png
'
echo "Wrote ${OUT}/icon.png and ${OUT}/logo.png"
