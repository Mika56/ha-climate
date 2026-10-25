# Releasing

Releases are cut by pushing a tag `vX.Y.Z`. `.github/workflows/release.yml` then builds one image per architecture
natively (`amd64` on `ubuntu-24.04`, `aarch64` on `ubuntu-24.04-arm`, no QEMU), pushes them to GHCR and creates the
GitHub Release.

| Image                                         | Tags                 |
| --------------------------------------------- | -------------------- |
| `ghcr.io/<owner>/amd64-ha-climate`            | `X.Y.Z`, `latest`    |
| `ghcr.io/<owner>/aarch64-ha-climate`          | `X.Y.Z`, `latest`    |

`<owner>` is the lower-cased repository owner. The Supervisor pulls `ghcr.io/<owner>/{arch}-ha-climate:<version>`
(the `image:` key of `ha_climate/config.yaml`), so the tag of the image is the add-on `version`.

## One-time setup: the repository owner

The repository ships with the literal placeholder `OWNER` (and `maintainer@example.com`) in `repository.yaml`,
`ha_climate/config.yaml`, `ha_climate/Dockerfile`, `ha_climate/DOCS.md`, `README.md`, `CONTRIBUTING.md` and
`SECURITY.md`. Replace them once, before the first release:

```bash
scripts/set-owner.sh <github-owner> [maintainer-email]
git diff && git commit -am "chore: set repository owner"
```

The script validates the owner, lower-cases it for registry paths, prints the files it changed and is idempotent.

## Cutting a release

1. Bump `version` in `ha_climate/config.yaml` (semantic versioning).
2. Add a `## X.Y.Z` section at the top of `ha_climate/CHANGELOG.md`; its content becomes the release notes.
3. Commit (`chore: release X.Y.Z`) locally, with CI green on the change itself. **Do not push `main` yet.**
4. Tag that commit and push **only the tag**:

   ```bash
   git tag -a vX.Y.Z -m "vX.Y.Z"
   git push origin vX.Y.Z
   ```

5. Wait for the `Release` workflow to succeed (both images pushed, GitHub Release created), then push `main`:

   ```bash
   gh run watch "$(gh run list --workflow release.yml --limit 1 --json databaseId --jq '.[0].databaseId')" --exit-status
   git push origin main
   ```

Why this order: the add-on store reads `config.yaml` from the default branch. If `main` carries the new version
before the images exist, every Supervisor that refreshes the store in that window (and auto-update installs) tries to
pull `ghcr.io/<owner>/{arch}-ha-climate:X.Y.Z` and fails with `404 manifest unknown` (seen with 0.1.2).

The workflow fails fast if the tag differs from `config.yaml` `version` or if the changelog has no matching section.

## After the first release: make the packages public

New GHCR packages are **private** by default, and Home Assistant users cannot pull private images. After the first
release, for **each** of the two packages (`amd64-ha-climate` and `aarch64-ha-climate`):

1. GitHub profile or organisation › **Packages** › the package › **Package settings**.
2. **Danger zone › Change visibility › Public**.
3. Optionally, in the same settings, link the package to the repository (the OCI `source` label already does it).

This is required once per package; later releases keep the visibility. Verify from a machine that is not logged in to
GHCR: `docker pull ghcr.io/<owner>/amd64-ha-climate:X.Y.Z`.

## Pitfalls

- Pushing the tag before the version bump fails the `Verify version` job; fix it and move/recreate the tag.
- The add-on store reads `config.yaml` from the default branch: push `main` only after the release workflow has
  published the images (see above), never before.
- Never publish an image tag different from `config.yaml` `version`, or the Supervisor will fail to pull it.
