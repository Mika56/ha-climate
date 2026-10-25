#!/usr/bin/env bash
# Replaces the OWNER placeholder (GitHub user or organisation) and the maintainer e-mail placeholder
# in the files that ship to users. Idempotent: once replaced, a second run changes nothing.
#
# Usage: scripts/set-owner.sh <github-owner> [maintainer-email]
set -euo pipefail

PLACEHOLDER_EMAIL='maintainer@example.com'
FILES=(
    repository.yaml
    ha_climate/config.yaml
    ha_climate/Dockerfile
    ha_climate/DOCS.md
    README.md
    CONTRIBUTING.md
    SECURITY.md
)

usage() {
    printf 'Usage: %s <github-owner> [maintainer-email]\n' "$(basename "$0")" >&2
    exit 2
}

[[ $# -ge 1 && $# -le 2 ]] || usage
owner="$1"
email="${2:-}"

# GitHub login rules: 1-39 characters, alphanumeric or single hyphens, no leading or trailing hyphen.
if [[ ! "${owner}" =~ ^[A-Za-z0-9]([A-Za-z0-9]|-[A-Za-z0-9]){0,38}$ ]]; then
    printf 'Invalid GitHub owner: %s\n' "${owner}" >&2
    exit 1
fi
if [[ -n "${email}" && ! "${email}" =~ ^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ ]]; then
    printf 'Invalid e-mail address: %s\n' "${email}" >&2
    exit 1
fi

# Container registry paths must be lowercase; URLs keep the owner as typed.
owner_lower="$(printf '%s' "${owner}" | tr '[:upper:]' '[:lower:]')"

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
changed=()

for file in "${FILES[@]}"; do
    path="${root}/${file}"
    [[ -f "${path}" ]] || continue
    tmp="$(mktemp)"
    sed -e "s|%2FOWNER%2F|%2F${owner}%2F|g" -e "s|ghcr\.io/OWNER\b|ghcr.io/${owner_lower}|g" -e "s|\bOWNER\b|${owner}|g" "${path}" > "${tmp}"
    if [[ -n "${email}" ]]; then
        sed -e "s|${PLACEHOLDER_EMAIL}|${email}|g" "${tmp}" > "${tmp}.new" && mv "${tmp}.new" "${tmp}"
    fi
    if ! cmp -s "${path}" "${tmp}"; then
        cat "${tmp}" > "${path}" # keeps the file mode
        changed+=("${file}")
    fi
    rm -f "${tmp}"
done

if [[ ${#changed[@]} -eq 0 ]]; then
    printf 'Nothing to change: no placeholder left.\n'
else
    printf 'Updated %d file(s):\n' "${#changed[@]}"
    printf '  %s\n' "${changed[@]}"
fi

if grep -qs "${PLACEHOLDER_EMAIL}" "${root}/repository.yaml"; then
    printf 'Note: the maintainer e-mail is still the placeholder; pass it as the second argument.\n' >&2
fi
