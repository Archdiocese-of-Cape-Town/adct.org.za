# ADCT Parish Intake

A WordPress plugin to collect parish notices and show upcoming events on the Archdiocese of Cape Town site. It runs on the existing shared host (PHP 8.2, WordPress database and scheduled jobs), without a separate server. Email is the first input; other channels are planned.

**Status:** Core pieces work in tests, but the full email-to-publication journey is **not ready for live rollout**. Received emails produce draft candidates; they do not yet trigger submitter confirmation and approval.

## What works now

- **Directory:** import and manage parishes, deaneries, venues, contacts, sources and approver assignments.
- **Inbox:** configure/test an IMAP mailbox, poll and privately store mail, extract draft events, and inspect/retry failures. The **Manual parser** works without a mailbox. Optional AI is off by default; manual parsing stays offline.
- **Events:** create/edit one-off or recurring WordPress events. The **Upcoming events** block or `[adct_events]` shortcode provides a date/type/parish/deanery-filtered listing; `/?adct_ics=1` provides an ICS feed. Drafts from email do **not** appear here automatically.
- **Operations:** bounded scheduled jobs, health reporting, a capped mail queue and single-use token foundations; automated PHP and installed-ZIP WordPress tests.

## Try it on a test WordPress site

1. On a **test** WordPress site (PHP 8.2+, MySQL/MariaDB), upload and activate `adct-parish-intake.zip` via **Plugins -> Add New -> Upload Plugin**. Use a GitHub Release ZIP or build one below, **not** GitHub's source-code ZIP.
2. Open **Parish Intake -> Manual parser** to try a fictional notice. For a public preview, publish a sample event under **Events -> Add New** and place the **Upcoming events** block or `[adct_events]` on a test page.
3. To test email separately, configure a **test** source and mailbox under **Parish Intake -> Sources / Mailboxes**, use **Test connection**, run jobs under **Parish Intake -> Scheduled jobs**, then inspect **Inbox**. Do not connect the live mailbox; enable outbound **Test mode** with an allow-list on test sites that may send mail.

For passwords, cron, roles and upgrades, see the [operator guide](docs/operator-guide.md).

## Run the project locally

From the repository root in PowerShell, use Docker Desktop (no host PHP needed):

```powershell
docker run --rm -v "${PWD}:/app" -w /app composer:2 install --no-interaction --no-progress
docker run --rm -v "${PWD}:/app" -w /app php:8.2-cli sh -c 'vendor/bin/phpunit && php tests/parser_smoke_test.php'
```

Build `dist/adct-parish-intake.zip`:

```powershell
docker run --rm -v "${PWD}:/app" -w /app composer:2 sh scripts/build-release.sh
```

For installed-ZIP WordPress tests, install Node.js and run `npm ci`, then `npm run test:integration`. Local runs **leave their isolated containers running**; use a unique `WP_ENV_HOME` and ports alongside other environments. See the [development guide](docs/development.md).

## What is still pending

Complete and validate email confirmation, dean/reviewer approval, change notices and the end-to-end pilot. Near-me search and single-event pages are separate work. Parish self-service, reminders, PDF/image extraction, Google Calendar, Facebook and WhatsApp inputs come later. A staging check and small parish pilot must precede live use.

The [backlog](docs/parish-intake-project-backlog.md) is a plan, **not** a completed-feature list. The [documentation index](docs/README.md) links to architecture and testing.
