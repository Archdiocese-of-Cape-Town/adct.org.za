# Development guide

How to work on the parish intake plugin: local setup, commands, rules, and the order of the first build session. Coding agents should also read [`.github/copilot-instructions.md`](../.github/copilot-instructions.md).

## Local setup (Windows, no PHP install needed)

PHP isn't installed on the development PC, but Docker Desktop is. Run PHP and Composer in containers from the repository root in PowerShell (the path contains spaces, so keep the quotes):

```powershell
# PHP 8.2, the same version as production (xneelo runs 8.2.33)
docker run --rm -v "${PWD}:/app" -w /app php:8.2-cli php tests/parser_smoke_test.php

# Composer (the composer:2 image runs a newer PHP, so composer.json must pin
# "config": { "platform": { "php": "8.2.0" } } to resolve packages for PHP 8.2)
docker run --rm -v "${PWD}:/app" -w /app composer:2 install --no-interaction --no-progress

# All tests (PHPUnit + prototype smoke test), the same as CI; use php:8.3-cli / php:8.4-cli to try other versions
docker run --rm -v "${PWD}:/app" -w /app composer:2 validate --no-check-publish
docker run --rm -v "${PWD}:/app" -w /app php:8.2-cli sh -c "vendor/bin/phpunit && php tests/parser_smoke_test.php"

# Lint every PHP file
docker run --rm -v "${PWD}:/app" -w /app php:8.2-cli sh -c 'find src tests adct-parish-intake.php -name "*.php" -print0 | xargs -0 -n1 php -l > /dev/null && echo lint-ok'
```

These commands were checked on 2026-09-24: the smoke test passes, lint is clean, and PHPUnit 11 runs on `php:8.2-cli` with the platform pin. Use **PHPUnit 11** (PHPUnit 12 needs PHP 8.3).

GitHub Actions (added in [#17](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/17)) runs the same tests on PHP 8.2, 8.3 and 8.4 for every PR. CI is the final judge.

## Build the release zip

Build the installable package from the repository root in PowerShell:

```powershell
docker run --rm -v "${PWD}:/app" -w /app composer:2 sh scripts/build-release.sh
```

This creates `dist/adct-parish-intake.zip`. The script installs production dependencies with `composer install --no-dev`, prefixes them with the pinned Strauss release into `vendor-prefixed/`, then packages only the plugin bootstrap, `src/`, and the prefixed runtime dependencies. Strauss is used instead of PHP-Scoper because it directly copies Composer dependencies into one prefixed directory and generates the autoloader the plugin uses. The current plugin does not read `data/seed/` at runtime, so seed data is not shipped.

The build pins Strauss 0.30.0 and verifies the official [release asset](https://github.com/BrianHenryIE/strauss/releases/download/0.30.0/strauss.phar) against SHA-256 `08c1a8e553594745c22294e158129005fd11ed09ed452d7d4f48566f38c66c96` before running it. To upgrade Strauss, calculate the SHA-256 of the chosen official release asset and update both `STRAUSS_VERSION` and `STRAUSS_SHA256` in `scripts/build-release.sh`, then rebuild the zip locally.

The build validates the zip by unpacking it, checking its contents, linting every packaged PHP file, and loading the plugin bootstrap and Core autoloader under plain PHP. The zip's small `WordPress\Autoloader` loads the plugin's `src/` classes; Composer's generated PSR-4 autoloader is used in development and tests. CI runs this same build on every PR and `v*` tag; PRs receive an `adct-parish-intake.zip` artifact.

Release tags must match the plugin header version exactly after removing the leading `v`. The build fails on a mismatch; it never edits the plugin header. To exercise that check locally without creating a tag or release:

```powershell
docker run --rm -e RELEASE_TAG=v0.1.0 -v "${PWD}:/app" -w /app composer:2 sh scripts/build-release.sh
```

## Where things are

| Path | What |
|---|---|
| `adct-parish-intake.php` | Plugin bootstrap (WordPress entry point) |
| `src/Core/` | Domain parsing pipeline, stages, value objects, pure-PHP helpers and ports; no WordPress functions, classes or globals |
| `src/Core/Jobs/` | Pure-PHP scheduled job contract, runner, budgets, checkpoint and run-state value objects |
| `src/WordPress/` | Plugin bootstrap, admin UI, schema/report adapters, OpenRouter provider and WordPress HTTP client |
| `src/WordPress/Jobs/` | WP-Cron registration plus option-backed job state and lock adapters |
| `composer.json` | PSR-4 autoloading for `ADCT\ParishIntake\Core\…` and `ADCT\ParishIntake\WordPress\…`; development/tests load through Composer |
| `src/WordPress/Autoloader.php` | Small PSR-4 source loader included in the release zip, where Composer's development autoloader is not shipped |
| `tests/parser_smoke_test.php` | Prototype smoke test; keep it until its cases are ported to PHPUnit with equal or stronger assertions ([#17](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/17)). |
| `tests/Unit/Architecture/CoreIsolationTest.php` | Token-based check that keeps WordPress APIs out of `src/Core/` |
| `data/seed/` | Deaneries and parishes CSVs for the directory import and preview sample data |
| `docs/` | Design, decisions (ADRs), backlog, testing |

## Rules for every change

1. **One issue per PR.** Branch from `main`, reference the issue (`Closes #N`) and follow its acceptance criteria. If the issue is unclear, comment on it instead of guessing big design changes.
2. **Tests first for bugs**, and every feature ships with tests. **Never remove or weaken a test because the implementation fails it.** If an expectation really is wrong, change it in a separate commit that explains why ([testing](testing.md)).
3. **PHP 8.2 compatible.** No 8.3+ syntax or functions (e.g. typed class constants, `json_validate`). No `ext-imap`.
4. **Core stays WordPress-free.** Code under `src/Core` (once #20 lands) must not call WordPress functions; use the ports (interfaces) and inject adapters.
5. **Portable SQL** that works on MySQL 8 and MariaDB 10.11, through `$wpdb` with prepared statements; schema changes only through versioned migrations.
6. **Shared-hosting limits** ([hosting environment](hosting-environment.md)): jobs default to a 60 s / 100-item budget with a 180 s lock lease and a checkpoint after each item; the runner's constructor and per-run arguments configure the budgets. No command line (no WP-CLI) is needed for any operation; all plugin email goes through the mail queue.
7. **Privacy (POPIA), and the repository is public:** never commit real parish emails, bulletins, posters, personal names, phone numbers, personal email addresses or secrets. Fixtures must be anonymised ([parser findings](parser-samples.md#fixture-guidance)). Secrets come from `wp-config.php` constants.
8. **Security:** capabilities + nonces on every admin action; escape output; action links are GET-shows-page / POST-acts with hashed single-use tokens ([ADR 0004](decisions/0004-trust-and-confirmation-model.md)).
9. **Docs in the same PR** when behaviour or design changes. New design decisions become an ADR in `docs/decisions/`.
10. Commit messages: short imperative summary line, then a body explaining why.

## Definition of done (per PR)

- Acceptance criteria of the issue met, and ticked in the PR description.
- Tests added or updated; the full suite passes locally (Docker) and in CI on PHP 8.2–8.4.
- No new WordPress calls in the core; lint clean.
- Docs updated where affected; no personal data or secrets in the diff.
- The plugin still activates and the existing **Parish Intake → Manual parser** screen still works (check in the Playground preview once #27 is in).

## First build session

Merge the planning PRs first so `main` has the current docs. Then build Phase 0 in this order, **one PR per issue**, each merged (CI green) before starting the next one that depends on it:

| Order | Issue | Why this order |
|---|---|---|
| 1 | [#17](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/17) E0.2 Composer, PHPUnit, CI | Everything else needs tests and CI. Port the smoke test cases with equal or stronger assertions. |
| 2 | [#21](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/21) E0.5 Date parsing fix | Small, well-defined, test-first; proves the test setup. Write the failing tests (e.g. `12/10/2026` → 12 October) before the fix. |
| 3 | [#20](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/20) E0.3 Core/WordPress split | A refactor with no behaviour change; the tests from 1 and 2 must stay green unchanged. |
| 4 | [#24](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/24) E0.8 Release zip | Needed by the preview button and every test site. |
| 5 | [#27](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/27) E0.9 Integration tests + Playground PR preview | After this, every PR has a one-click preview. The publish workflow only runs once merged to `main`. |
| 6 | [#19](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/19) E0.4 Fixture corpus harness | Harness plus anonymised fixtures; needs a person to anonymise real samples (never commit originals). |
| 7 | [#22](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/22), [#23](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/23), [#25](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/25), [#26](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/26) | Migrations/schema, job framework, roles, secrets: the base for Phase 1. |

Stop and ask the project owner before: changing an accepted ADR, adding a paid service, adding a dependency that isn't pure PHP, or anything that sends real email or touches the live site.
