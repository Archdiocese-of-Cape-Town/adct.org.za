# Testing

Tests protect the project from breaking as more people and sessions work on it.

**Rule: never remove or weaken a test just because the implementation fails it.** Fix the implementation. If a test's expectation really is wrong, change it in its own commit that explains why (e.g. "fixture expected US date order; SA uses DD/MM").

## Test layers

| Layer | What | Where it runs | When |
|---|---|---|---|
| 1. Unit | Domain core: parsing stages, date/recurrence, matching, trust rules, tokens, MIME parsing. No WordPress. | GitHub Actions + locally | Every push / PR |
| 2. Parser fixtures ("golden" tests) | Anonymised real parish emails (`tests/fixtures/emails/*.eml`) with expected output (`*.expected.json`). | GitHub Actions + locally | Every push / PR |
| 3. WordPress integration | Plugin activation, migrations, post type, roles/capabilities, token pages, ICS output, REST filters. | GitHub Actions using `wp-env` (Docker) or WordPress Playground CLI | Every PR |
| 4. IMAP integration | Polling, duplicate prevention, checkpoints, folder moves, retention, against a throwaway IMAP server (GreenMail in a Docker service container). | GitHub Actions | Every PR touching ingestion |
| 5. Manual preview | Click-through of admin/portal/public UI. | WordPress Playground, TasteWP, InstaWP | As needed |
| 6. Staging | Real xneelo PHP/cron/mail limits with a test mailbox. | `staging.adct.org.za` | Before every release |

The CI matrix runs PHP **8.2** (production), **8.3** and **8.4**.

## Parser fixture corpus

- Collect real examples: bulletins, posters (PDF/image), one-line notices, forwarded emails, replies with quoted text, recurring schedules, cancellations, changes.
- Anonymise personal names, phone numbers and private addresses before committing. Parish names and public church addresses can stay.
- Each fixture has an expected JSON with only the fields that matter (e.g. number of events, title, start date/time, recurrence, parish). A test runner compares the parser's output with it and prints a readable diff.
- A **score report** (fields right / total) is printed in CI. When parsing rules improve, the score should not go down.
- Every parser bug report should add a fixture first (failing), then the fix.

## Manual preview options

- **WordPress Playground** (`playground.wordpress.net`): free, no account, runs WordPress in the browser. A blueprint in `.github/playground/blueprint.json` can install the latest release zip and sample data, so a PR can include a "Preview in Playground" link. Limitation: no raw socket connections, so IMAP polling can't be tested there. Use the manual parser and sample data instead.
- **TasteWP / InstaWP**: free temporary WordPress sites on real servers. Upload the release zip. They can reach external IMAP/SMTP, so they're useful for trying a test mailbox end-to-end. Sites expire, so don't keep anything important there, and use only a **test** mailbox with a throwaway password.
- **Local**: `wp-env` (needs Docker) or Local (by WP Engine) for developers.

## Staging on xneelo

- `staging.adct.org.za` with its own database, the plugin release zip, and a separate `events-test@adct.org.za` mailbox.
- Outgoing email on staging should only go to an allow-list of test addresses (setting: "Staging mode – only send to: …").
- Release checklist: install zip → run migrations → send test emails (single event, bulletin, poster PDF, recurring event) → confirm via the emailed links → check the events page and ICS feed → check the health dashboard.

## Running tests locally

Once Phase 0 CI setup is done, these will be available:

```bash
composer install
composer test            # unit + fixture tests
composer test:fixtures   # fixture corpus with score report
```

Until then, the prototype smoke test runs with:

```bash
php tests/parser_smoke_test.php
```
