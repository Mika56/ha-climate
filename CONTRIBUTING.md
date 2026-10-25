# Contributing

Thanks for helping! Issues and pull requests are welcome at <https://github.com/Mika56/ha-climate>.

## Workflow

1. Open an issue for anything non-trivial before starting.
2. Work on a branch; keep pull requests focused.
3. Use [Conventional Commits](https://www.conventionalcommits.org/) (`feat:`, `fix:`, `docs:`, `ci:`,
   `build(addon):`, `chore:` ...).
4. Run the gates locally before pushing: `make ci` (PHP and front end, in Docker; see the README for the details).
5. Everything is written in English (code, comments, docs, commit messages). User-facing strings go through the
   translation files (English and French).

## Rules of the codebase

- php-cs-fixer `@Symfony`, PHPStan at level max, no baseline.
- Every PHPUnit test class declares `#[CoversClass]`; line coverage must stay above 90 %.
- API changes start in `api/openapi.yaml`; regenerate the front-end types with `npm run api:types`.
- Never commit secrets or tokens (`docker/dev/.env.dev` is git-ignored on purpose).

## Releases

Maintainers only: see [docs/releasing.md](docs/releasing.md).
