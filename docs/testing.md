# Testing

Tests protect the project from breaking as more people and sessions work on it.

**Rule: never remove or weaken a test just because the implementation fails it.** Fix the implementation. If a test's expectation really is wrong, change it in its own commit that explains why (e.g. "fixture expected US date order; SA uses DD/MM").

## Test layers

| Layer | What | Where it runs | When |
|---|---|---|---|
| 1. Unit | Domain core: parsing stages, date/recurrence, matching, trust rules, tokens, MIME parsing. Includes a token-based check that `src/Core/` has no WordPress dependencies. | GitHub Actions + locally | Every push / PR |
| 2. Parser fixtures ("golden" tests) | Anonymised real parish emails (`tests/fixtures/emails/*.eml`) with expected output (`*.expected.json`). | GitHub Actions + locally | Every push / PR |
| 3. WordPress integration | Activation from the built release zip without PHP errors/notices; the Parish Intake admin menu and Manual parser page register and render for an administrator. | GitHub Actions using `wp-env` (Docker) and WP-CLI | Every PR |
| 4. IMAP integration | Polling, duplicate prevention, checkpoints, folder moves, retention, against a throwaway IMAP server (GreenMail in a Docker service container). | GitHub Actions | Every PR touching ingestion |
| 5. Manual preview | Click-through of admin/portal/approver/public UI. | WordPress Playground **PR preview button** (every PR); InstaWP / TasteWP with the CI-built zip for real mail | Every PR (Playground); as needed (InstaWP/TasteWP) |
| 6. Pre-launch check | Real xneelo PHP/cron/mail limits with a test mailbox. | A **temporary** staging instance on xneelo, removed afterwards (no permanent staging) | Once before launch; optionally before big releases |

The unit-test CI matrix runs PHP **8.2** (production), **8.3** and **8.4**. The WordPress integration job runs separately on PHP 8.2.

See [ADR 0009](decisions/0009-preview-and-test-environments.md) for why previews and test sites are set up this way.

## Planned approval flow test cases

The following cases will be added alongside the approval work ([ADR 0008](decisions/0008-approval-by-dean-or-archdiocese-reviewer.md)). The current WordPress integration suite covers plugin activation and the Manual parser admin screen only:
- A new event from a verified contact doesn't publish after confirmation alone. It goes to `awaiting_approval`.
- An awaiting item is visible to the parish's deanery approvers **and** to archdiocese reviewers, and not to approvers of other deaneries.
- First to act wins: two approvals (or an approve and a reject) for the same item leave exactly one decision. The second action gets "already decided".
- Self-approval: a dean submitting for a parish in their deanery, or a reviewer submitting anything, publishes on confirmation with `approved_via = self`.
- A dean submitting for a parish **outside** their deanery still needs approval.
- A change or cancellation by a verified contact to a published event publishes immediately, writes `event_changes`, and notifies approvers. Revert restores the previous version.
- A change from an unknown sender or a monitored source needs approval.
- A group without a deanery goes to reviewers only.
- A parish in a deanery with **no active approver** (dean not set up) goes to reviewers only, is approved by a reviewer, and the dashboard lists that deanery as "no approver".
- An unchanged repeat of a published or pending event (same parish, title, schedule) is marked `duplicate` and sends **no** confirmation or approval email.
- Approver digest mode sends one daily email instead of one per item. Reminders respect the on/off switches.
- Approve/reject links: a GET never changes state, and tokens are single-use and expire.

## Scheduling and mail limits test cases

From [ADR 0010](decisions/0010-scheduled-jobs-with-2-hour-cron-limit.md) and [ADR 0011](decisions/0011-outbound-email-queue-with-hourly-cap.md):
- Two triggers at once (visitor + pinger): only one job run does the work (lock).
- A job that is "due" runs on the first trigger after its due time. Long gaps (2 h) don't cause duplicate or lost work.
- "Check now" runs the poll and queue within the time budget and needs the right capability and a nonce.
- Health warning appears when the last run is older than 2 h 15 min.
- Mail queue: hourly cap enforced across runs; priority 1 goes before priority 3; notices grouped per approver; retries with backoff; Test mode suppresses non-allow-listed recipients.

## Parser fixture corpus

Real samples reviewed so far, and what they taught us, are in [parser findings](parser-samples.md). The originals are kept privately (not in this public repository).

- Collect real examples: bulletins, posters (PDF/image), one-line notices, forwarded emails, replies with quoted text, recurring schedules, cancellations, changes.
- Anonymise personal names, phone numbers, personal email addresses, bank details and private addresses before committing. Replace sick lists and Mass intentions with fake names but keep their headings (the skip-section tests need them). Parish names, public church addresses and `@adct.org.za` office addresses can stay.
- Include each sample type: text-layer bulletin (multi-column), multi-church bulletin, printed Mailchimp email, text-layer poster, image-only poster.
- Each fixture has an expected JSON with only the fields that matter (e.g. number of events, title, start date/time, recurrence, parish). A test runner compares the parser's output with it and prints a readable diff.
- A **score report** (fields right / total) is printed in CI. When parsing rules improve, the score should not go down.
- Every parser bug report should add a fixture first (failing), then the fix.

## Manual preview options

All of these use the **same zip that CI builds**. The plugin bundles prefixed Composer dependencies, so the raw repository folder can't be installed directly.

- `.github/workflows/pr-preview-build.yml` builds `adct-parish-intake.zip` on every PR and `v*` tag. Before uploading the artifact, it verifies the archive layout and excluded paths, lints all packaged PHP files, and loads the plugin bootstrap under plain PHP.
- **WordPress Playground PR previews** (`playground.wordpress.net`): free, no account, runs WordPress in the browser.
  - The official [`WordPress/action-wp-playground-pr-preview`](https://github.com/WordPress/action-wp-playground-pr-preview) Action adds a **"Preview in WordPress Playground"** button to every PR. It uses two workflows:
    - `pr-preview-build.yml` uses the Action's read-only build workflow to run `scripts/build-release.sh` and bundle the release zip plus the Blueprint.
    - `pr-preview-publish.yml` uses the Action's `workflow_run` publisher to upload the bundle's zip to a public `ci-artifacts` prerelease and add the button. GitHub reads this privileged workflow from `main`, so the publisher only takes effect after it is merged there. It never checks out or executes pull request code; it treats the artifact as untrusted data.
  - The blueprint `.github/playground/blueprint.json` installs and activates the release zip, logs in as `admin`, and opens the Manual parser page. **TODO (follow-up to #22):** add anonymised parish and event sample data after the schema and migrations exist; the current blueprint does not invent or load a data structure that is not implemented yet.
  - Limitation: no raw socket connections, so IMAP polling can't be tested there.
- **InstaWP / TasteWP**: free temporary WordPress sites on real servers.
  - Install the CI-built zip by URL. They can reach external IMAP/SMTP, so use them to try a test mailbox end to end, with **Test mode** on (outbound email only to allow-listed addresses).
  - InstaWP can also deploy from a GitHub branch and has a per-PR GitHub Action. Its Composer step is a paid feature, so the zip is simpler.
  - Sites expire, so don't keep anything important there, and use only a **test** mailbox with a throwaway password.
- **Local**: `wp-env` (needs Docker) or Local (by WP Engine) for developers.

## Pre-launch check on a temporary staging instance

There is no permanent staging site. Before the first launch (and optionally before big releases):
- Create a temporary xneelo instance (e.g. a subdomain with its own database) with the release zip and a separate test mailbox (e.g. `events-test@adct.org.za`).
- Turn on **Test mode**, so outgoing email only goes to an allow-list of test addresses.
- Release checklist: install zip → run migrations → send test emails (single event, bulletin, poster PDF, recurring event) → confirm via the emailed links → approve as a dean and as a reviewer → make a change as a verified contact and revert it → check the events page and ICS feed → check the health dashboard, that the 2-hourly xneelo cron and the external pinger both trigger jobs within the time budget, and that the mail queue respects the hourly cap.
- Remove the instance afterwards. Launch starts with a few pilot parishes.

## Running tests locally

With PHP and Composer installed:

```bash
composer install
composer test        # PHPUnit (tests/Unit) + prototype smoke test
composer test:unit   # PHPUnit only
composer test:integration # WordPress integration tests; requires npm ci and a built release zip
```

`composer test` includes `CoreIsolationTest`, which tokenizes every PHP file under `src/Core/` and rejects WordPress function calls (`wp_*`, `esc_*`, `sanitize_*`, translation helpers such as `__()`/`_e()`, and the listed global WordPress APIs including `get_option`, `add_action`, `current_user_can`, and `dbDelta`), `WP_*` classes, WordPress constants such as `ABSPATH`/`ARRAY_A`, and WordPress globals such as `$wpdb`/`$wp`. It also catches `function_exists()` checks for those APIs. Comments and ordinary strings are ignored; PHP-native helpers such as `function_exists('mb_strtolower')` are allowed.

Without a local PHP, use the Docker commands in the [development guide](development.md#local-setup-windows-no-php-install-needed). CI (`.github/workflows/ci.yml`) runs `composer validate`, a `php -l` lint and `composer test` on PHP 8.2, 8.3 and 8.4 for every PR and push to `main`.

The integration suite runs against a disposable `wp-env` Docker environment and installs `dist/adct-parish-intake.zip` with WP-CLI. The raw repository checkout is not activated or used as the plugin. With Node.js/npm and Docker Desktop installed, run:

```powershell
npm ci
docker run --rm -v "${PWD}:/app" -w /app composer:2 sh scripts/build-release.sh
npm run test:integration
```

The test runner creates and stops its own isolated `wp-env` environment. Its test-only Docker data is retained under the system temporary directory for faster local reruns; CI runners discard it with the job. `composer test` remains the unit/smoke suite and does not start WordPress. CI (`.github/workflows/ci.yml`) runs `composer validate`, a `php -l` lint and `composer test` on PHP 8.2, 8.3 and 8.4 for every PR and push to `main`, plus the WordPress integration job on PHP 8.2.

`wp-env` is used instead of the Playground CLI for integration tests because it supplies a normal WordPress/MySQL environment and WP-CLI in Docker. That lets CI install the exact release zip and exercise the admin menu/page without browser automation.

A `composer test:fixtures` command for the fixture corpus score report will be added with the fixture work.
