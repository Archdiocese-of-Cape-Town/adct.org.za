# Copilot instructions: ADCT Parish Intake

A WordPress plugin for the Archdiocese of Cape Town. Parishes email event notices to `events@adct.org.za`. The plugin parses them (offline first; AI optional), emails the submitter a preview to confirm, routes each new event to the dean or an archdiocese reviewer for approval, and publishes to an events page and ICS feed on adct.org.za. The users are mostly non-technical.

## Read before changing code
- `docs/development.md`: setup (PHP runs in Docker; it isn't installed locally), rules, definition of done, and the build order.
- `docs/architecture.md`, `docs/data-model.md`, `docs/hosting-environment.md`, `docs/testing.md`.
- `docs/decisions/`: accepted ADRs are binding. Don't contradict them; propose a new ADR instead.
- The GitHub issue you're working on. Its acceptance criteria define "done".

## Commands (PowerShell, repository root)
```powershell
docker run --rm -v "${PWD}:/app" -w /app composer:2 install --no-interaction --no-progress
docker run --rm -v "${PWD}:/app" -w /app php:8.2-cli vendor/bin/phpunit
docker run --rm -v "${PWD}:/app" -w /app php:8.2-cli php tests/parser_smoke_test.php
```
`composer.json` must keep `"config": {"platform": {"php": "8.2.0"}}`, because the composer image runs a newer PHP. Use PHPUnit 11.

## Hard rules
- **Never remove, skip or weaken a test to make it pass.** Fix the code. If an expectation really is wrong, change it in its own commit that explains why.
- Add tests with every change. For bugs, write the failing test first.
- Target **PHP 8.2** (production is 8.2.33 on xneelo shared hosting). CI also runs 8.3 and 8.4. There is no `ext-imap`.
- `src/Core` (after issue #20) must not call WordPress functions. Use interfaces (ports) and inject adapters.
- SQL must work on both MySQL 8 and MariaDB 10.11. Use `$wpdb->prepare`. Change the schema only through versioned migrations.
- Hosting limits:
  - 90 s PHP limit, so jobs have a ~60 s budget with a lock and checkpoint.
  - Cron runs at most every 2 hours (ADR 0010).
  - No WP-CLI, so every operation needs an admin screen or button.
  - 500 emails/hour for the whole account, so all email goes through the plugin's mail queue (ADR 0011).
- **The repository is public, and POPIA applies.** Never commit real parish emails, bulletins, posters, names, phone numbers, personal email addresses, bank details or secrets. Fixtures must be anonymised. Secrets come from `wp-config.php` constants.
- Security:
  - Check capabilities and nonces on every admin action; escape all output.
  - Emailed action links: a GET shows a page, only a POST acts; tokens are hashed, single-use and expire.
- Dates and times: `Africa/Johannesburg`, day-first dates (`12/10/2026` is 12 October). Inject a clock instead of reading "now".
- One issue per PR, with `Closes #N`. Update the related docs in the same PR.
- Ask the project owner before: changing an accepted ADR, adding a paid service or a non-pure-PHP dependency, or anything that sends real email or touches the live site.
