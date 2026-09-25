# Testing

Tests protect the project from breaking as more people and sessions work on it.

**Rule: never remove or weaken a test just because the implementation fails it.** Fix the implementation. If a test's expectation really is wrong, change it in its own commit that explains why (e.g. "fixture expected US date order; SA uses DD/MM").

## Test layers

| Layer | What | Where it runs | When |
|---|---|---|---|
| 1. Unit | Domain core: parsing stages and directory lookup, date/recurrence phrase rules and anchors, event details and RFC 5545 subset validation, recurrence preset mapping, RRULE occurrence expansion (filters, ordinal weekdays, COUNT/UNTIL, EXDATE/RDATE, leap dates, fast-forwarding and timezone behavior), the rolling window and checkpointed job, transactional occurrence replacement/rollback, schema migration logic, matching, trust rules, tokens, MIME parsing, declared/likely automated-mail and mailing-list detection, structured SPF/DKIM/DMARC parsing with untrusted-authserv defaults, confirmation/instant-change policies, bounded inbound reprocessing, sanitized failure diagnostics and failure/retry idempotency, status-filtered Inbox queries, draft-candidate replacement that preserves reviewed rows, IMAP protocol, mailbox UID checkpoint/resume and UIDVALIDITY-safe oversized moves, source polling cadence and retry backoff, and versioned snapshot cache invalidation with fakes. Includes a token-based check that `src/Core/` has no WordPress dependencies. | GitHub Actions + locally | Every push / PR |
| 2. Parser fixtures ("golden" tests) | Fictional or safely anonymised parish emails (`tests/fixtures/emails/*.eml`) with expected output (`*.expected.json`). | GitHub Actions + locally | Every push / PR |
| 3. WordPress integration | Activation from the built release zip without PHP errors/notices; schema v6 creates all 17 `adct_pi_*` tables and the fresh mail queue composite unique index; a simulated v3 upgrade applies v4–v6, a v4-to-v5 upgrade preserves rows and rejects duplicate recipient/key pairs without deletion, and a v5-to-v6 upgrade creates the primary-keyed hashed rate-limit table; action-token GET previews leave state untouched, nonce-protected POST consumes once, invalid nonces do not act, same-second email/IP renewal limits prevent extra queue enqueues on MariaDB, and fresh rate-limit scopes remain admissible after more than 20 distinct bucket inserts; the event post type and hierarchical taxonomy register, default terms remain idempotent on repeated activation, manual editor and REST writes create/refresh occurrence rows, draft/pending/private events remain unindexed, archdiocese-wide events use NULL parish/location, status flags and the daily rolling-window job work; valid event metadata saves through the WordPress handler, invalid metadata is rejected without replacing valid values, public REST metadata excludes contact details, subscribers cannot update event meta, and Editors/Intake managers can; uninstall removes namespaced ADCT capabilities without touching generic event capabilities; mailbox settings persist with an archdiocese-wide email source, the active mailbox is available to the registered polling job, message/content-hash duplicates are rejected, skipped-message/attachment warnings and recent automated-mail/authentication summaries render without exposing sender, subject or raw headers, and saved passwords never render; the Inbox renders status filters and safe error notes, and a deliberately broken stored message can be reprocessed from the same row and raw file into one draft candidate without changing attachments or the mailbox checkpoint; directory imports seed provisional defaults, linked outstation venues and official office-email sources idempotently; parish sources can be registered and switched official, health can be recorded, and the Sources submenu and parish Sources tab render; venue creation, default selection, location lookup and the parish Venues tab work; the Parish Intake menu and Manual parser page register and render; the Manual parser resolves a parish and default venue from a test-created verified sender, renders every bulletin candidate and stores the first in the legacy table. | GitHub Actions using `wp-env` (Docker) and WP-CLI | Every PR |
| 4. IMAP integration | SMTP delivery followed by mailbox search, raw fetch, folder creation, seen marking and move against throwaway GreenMail; the polling job stores an attachment, resumes after an item-budget stop, de-duplicates a resend with a new Message-ID, records and moves an oversized message without blocking later mail, persists automated-mail flags and structured untrusted SPF/DKIM/DMARC verdicts, and moves successful messages to Processed; the mailbox test-connection service checks successful login, wrong-password and missing-processed-folder results, including source health updates. The test group is isolated from the default unit suite. | Separate GitHub Actions job with a pinned GreenMail service image | Every PR |
| 5. Manual preview | Click-through of admin/portal/approver/public UI. | WordPress Playground **PR preview button** (every PR); InstaWP / TasteWP with the CI-built zip for real mail | Every PR (Playground); as needed (InstaWP/TasteWP) |
| 6. Pre-launch check | Real xneelo PHP/cron/mail limits with a test mailbox. | A **temporary** staging instance on xneelo, removed afterwards (no permanent staging) | Once before launch; optionally before big releases |

The unit-test CI matrix runs PHP **8.2** (production), **8.3** and **8.4**. The WordPress integration job and GreenMail IMAP integration job run separately on PHP 8.2.

Issue #54 adds Core date-window validation and release-ZIP WordPress integration coverage for the public shortcode and server-rendered block, pagination through the 1,800-row fixture (including pages 90–91), invalid dates, cache invalidation and private-post suppression. Custom date ranges must not create transients; preset cache keys are fixed by period and page. Its deterministic load fixture seeds 150 fictional parishes, 150 published posts and 1,800 occurrence rows spread over the next year. A cold 20-card **upcoming** listing must finish in **5 seconds or less with at most 100 SQL queries**; a cached repeat must finish in **2 seconds or less** in the isolated CI WordPress environment. These thresholds reserve at least 85 seconds of xneelo's 90-second PHP request budget and prevent a per-occurrence query pattern. Timings include rendering, not fixture creation; they are regression guards rather than a production SLA.

For an isolated local WordPress integration run, set `WP_ENV_HOME` to a new unique directory and choose free `WP_ENV_PORT` / `WP_ENV_TESTS_PORT` values. The harness stores a generated `.wp-env.json` with absolute repository mappings in a deterministic, ignored cache directory keyed by the resolved home and repository config. Reusing the same `WP_ENV_HOME` with unchanged repository config reuses the same `wp-env` project and its Docker volumes; choose a different home for a fresh database. The generated config is retained so the project path remains stable. The harness stops only that project and never deletes Docker volumes, the caller's home, or a config it cannot verify. Run `npm run test:integration-config` to check this path behavior without starting Docker. Without a `WP_ENV_HOME` override, the existing shared temporary default and repository config path are preserved.

Issue #48 coverage includes option-backed test mode defaulting off, exact address/domain matching, fail-closed empty and malformed configuration, admin banner and preview authorization/escaping, no mail-preview REST route, and suppression of a row queued before settings change. The integration test's `pre_wp_mail` callback exists only around synthetic queue delivery and rejects anything outside `example.test`; production code does not install a global mail filter.

E4.7 unit tests cover the outbound queue's rolling cap across runs, atomic in-flight reservations, priority ordering (including a login link ahead of 200 digests), per-recipient group-key idempotency, exponential retries and terminal failure, retry after an interrupted claim, unknown delivery exceptions retaining reservations, and allowlist suppression without delivery or cap usage. WordPress integration tests verify the sender job wiring and database-backed queue state, including a login link queued behind 200 digests; a `pre_wp_mail` interception accepts only fake `example.test` recipients so the test never sends real mail.

See [ADR 0009](decisions/0009-preview-and-test-environments.md) for why previews and test sites are set up this way.

## Planned approval flow test cases

The route-resolution subset is covered by #68: unit tests exercise two active approvers, a parish without a deanery, no active approvers (including an inactive assignment), and an inactive deanery. The WordPress integration suite also assigns two approvers to a deanery, resolves routes for its parishes, checks reviewer-only behavior without a deanery, and verifies role retention and removal. End-to-end event lifecycle cases remain planned alongside the approval work ([ADR 0008](decisions/0008-approval-by-dean-or-archdiocese-reviewer.md)):
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
- Action links: GET renders only a safe preview; nonce-protected POST consumes a token once; invalid, expired and used tokens have friendly states; renewal is queued without exposing recipient data in the page. MariaDB integration tests verify that two concurrent conditional token-consume updates admit exactly one winner, same-second N+1 request denial for both per-email and per-IP limits, and no additional queue delivery after the cap. No event approval or login handler is exercised until its separate issue.

## Scheduling and mail limits test cases

From [ADR 0010](decisions/0010-scheduled-jobs-with-2-hour-cron-limit.md) and [ADR 0011](decisions/0011-outbound-email-queue-with-hourly-cap.md):
- Unit tests cover the time and item budgets, a checkpoint saved after every item, resumption from incomplete mailbox scans, per-source polling intervals, immediate polling for never-checked sources, failed-poll retry backoff, the bounded inbound-message processing job, due checks, overlapping-run prevention, expired-lock recovery, stale-token release protection, and per-run lifecycle reset for stateful jobs. Occurrence-job tests also verify that an incomplete batch keeps a fixed window and resumes at the next published event.
- Unit tests verify that exceptions release the lock and record the last error, and that `last_success_at` advances only after a successful batch.
- The WordPress adapters are checked for non-autoloaded state, option-backed lock creation, expiry and malformed-lock recovery, token-guarded release, action-token hash-only persistence and atomic consumption, and fail-closed rate-limit decisions independent of affected-row semantics.
- WP-Cron scheduling and cleanup failures are logged without escaping the scheduler; unexpected cron callback failures are logged and recorded when job state can still be saved.
- WordPress integration tests cover the custom ten-minute cron hook, deactivation cleanup, Run now capability/nonce flow, mailbox polling and occurrence job scheduling, the v3 upgrade through v6, the v4-to-v5 unique mail-index upgrade, and the v5-to-v6 rate-limit-table upgrade on MariaDB.
- The scheduled-jobs page reports per-job state. The health warning after 2 h 15 min remains a future health-dashboard behavior.
- Mail queue: the successful-recipient cap is enforced across runs with reservations for concurrent sends; priority 1 goes before priorities 2–3; identical composed per-recipient notices are idempotent by `group_key`; changed content under the same key errors; retries use exponential backoff and stop after 5 attempts; suppressed allowlist recipients never reach `wp_mail()` or count against the cap.
- A crash or thrown delivery exception with an unknown outcome holds its reservation for 60 minutes before retry. This protects the cap, but if SMTP accepted the message before the process died, that retry may deliver one duplicate. The unit tests cover the reservation window and retry.

## Parser fixture corpus

Real samples reviewed so far, and what they taught us, are in [parser findings](parser-samples.md). The originals are kept privately (not in this public repository).

- Collect real examples: bulletins, posters (PDF/image), one-line notices, forwarded emails, replies with quoted text, recurring schedules, cancellations, changes.
- The current fixture corpus uses **invented examples only**. The issue's "10 anonymised real samples" criterion is deferred to a human; these fixtures do not satisfy or claim that criterion. Do not open, copy, or send the private originals to this public repository or an automated agent.
- Invented recurrence fixtures cover an inferred first-Friday anchor, a Tuesday/Thursday weekly rule, a nine-day novena, and an ambiguous Advent schedule. Unit tests cover the other supported phrases, verify every emitted rule with `RRuleValidator`, and check that seasonal wording does not invent dates or an RRULE.
- Before a person adds any real-derived example, follow the [fixture anonymisation guide](fixture-anonymisation.md). Replace personal names, phone numbers, personal email addresses, bank details and private addresses. Replace names in sick lists and Mass intentions but keep their headings. Parish names, public church addresses and `@adct.org.za` office addresses must also be replaced in this public repo.
- Cover the structures described in [parser findings](parser-samples.md): text-layer and multi-church bulletins, printed-email style notices, text posters, image-only posters, forwarded messages, replies with quoted text, recurring schedules, date ranges, cancellations/postponements, and administrative notices.
- Each fixture is a pair: `tests/fixtures/emails/<name>.eml` and `tests/fixtures/emails/<name>.expected.json`. A fixture may set `directory_snapshot` to a JSON file in `tests/fixtures/` (the invented directory is shared from `tests/fixtures/directory.json`) to test parish, venue and sender resolution. Existing top-level parse fields continue to compare against the first/primary `ParseResult`. Bulletin fixtures may additionally provide `candidate_count`, a `candidates` list and `blocks` metadata; each candidate can assert `block_index`, `fields` (including title/date/time and directory match provenance), and other `ParseResult::toArray()` values. Skip metadata contains only a block index, `classification: skipped` and a category reason; never add skipped source text to expected output. A missing expected key is not checked; list lengths are checked. `known_failures` maps an output path (or parent path) to an existing issue. Mismatches covered by known issues are reported as incomplete; any untracked mismatch fails with a per-field diff. Keep the fixture and update the expectation only when the relevant issue is fixed.
- Every `.eml` must have a `From` header and a `Date` header with an explicit timezone so fixture metadata and relative dates stay deterministic. `EmailFixtureLoader` delegates to the production `Core\Ingestion\MimeMessageParser`, so fixture tests exercise real MIME transfer/charset decoding, alternative/related/mixed parts, HTML-to-text conversion, and quote/signature/newsletter cleanup. Attachment metadata and MIME part references are checked; attachment bytes are intentionally not extracted.
- The runtime MIME parser is pure PHP and does not require `ext-imap` or `ext-dom`. Added invented fixtures cover Outlook HTML, Gmail and Apple Mail alternatives, mobile signatures, forwarded and quoted replies, Windows-1252, a Mailchimp-style newsletter, and bulletins containing every configured non-event section category beside real event examples. Unit tests verify all categories, weekly Mass-times tables, section boundaries, custom/sanitized keyword lists, and that skipped text is absent from outcomes and AI-provider input. Use invented names, `example.test` addresses and only fictional phone numbers in the `021 555 01xx` range; never include real parish samples, personal or bank details.
- To add a fixture, add those two files, then run `composer test` and `composer fixture-score`. The PHPUnit provider discovers fixture pairs automatically, and both runners apply the optional directory snapshot. Bulletin examples should assert all candidates with `candidate_count` and `candidates`; single-event fixtures may keep their existing top-level fields unchanged.
- A **score report** (fields right / total) is printed in CI. When parsing rules improve, the score should not go down.
- Every parser bug report should add a fixture first (failing), then the fix.

## Inbound-mail screening fixtures

`tests/fixtures/inbound-mail/` contains synthetic `.eml` files paired with `.expected.json` assertions. They cover out-of-office, delivery-status bounce, mailing-list, no-reply, ordinary parish and spoofed Authentication-Results messages, plus isolated header-signal cases. These are consumed by `RawMessageInspectorTest`, separate from the event-parser golden corpus. Fixtures use only `example.test` identities; auth verdicts remain untrusted unless a test explicitly configures an authserv-id.

## Manual preview options

All of these use the **same zip that CI builds**. The plugin bundles prefixed Composer dependencies, so the raw repository folder can't be installed directly.

- `.github/workflows/pr-preview-build.yml` builds `adct-parish-intake.zip` on every PR and `v*` tag. Before uploading the artifact, it verifies the archive layout and excluded paths, lints all packaged PHP files, and loads the plugin bootstrap under plain PHP.
- **WordPress Playground PR previews** (`playground.wordpress.net`): free, no account, runs WordPress in the browser.
  - The official [`WordPress/action-wp-playground-pr-preview`](https://github.com/WordPress/action-wp-playground-pr-preview) Action adds a **"Preview in WordPress Playground"** button to every PR. It uses two workflows:
    - `pr-preview-build.yml` uses the Action's read-only build workflow to run `scripts/build-release.sh` and bundle the release zip plus the Blueprint.
    - `pr-preview-publish.yml` uses the Action's `workflow_run` publisher to upload the bundle's zip to a public `ci-artifacts` prerelease and add the button. GitHub reads this privileged workflow from `main`, so the publisher only takes effect after it is merged there. It never checks out or executes pull request code; it treats the artifact as untrusted data.
  - The blueprint `.github/playground/blueprint.json` installs and activates the release zip, logs in as `admin`, and opens the Manual parser page. **TODO (follow-up now that #22 has landed):** add anonymised parish and event records through a fixture/seed mechanism; the current blueprint intentionally does not load application data until that mechanism exists.
  - Limitation: no raw socket connections, so IMAP polling can't be tested there.
- **InstaWP / TasteWP**: free temporary WordPress sites on real servers.
  - Install the CI-built zip by URL and enable **Parish Intake → Outbound email → Test mode** before testing delivery. Use a test mailbox and allow-list only its exact address/domain; verify the admin banner and suppressed-mail log. The safeguard affects Parish Intake queue mail only, not other plugins.
  - InstaWP can also deploy from a GitHub branch and has a per-PR GitHub Action. Its Composer step is a paid feature, so the zip is simpler.
  - Sites expire, so don't keep anything important there, and use only a **test** mailbox with a throwaway password.
- **Local**: `wp-env` (needs Docker) or Local (by WP Engine) for developers.

## Pre-launch check on a temporary staging instance

There is no permanent staging site. Before the first launch (and optionally before big releases):
- Create a temporary xneelo instance (e.g. a subdomain with its own database) with the release zip and a separate test mailbox (e.g. `events-test@adct.org.za`).
- Before connecting SMTP or testing confirmations, enable **Parish Intake → Outbound email → Test mode**, add only the test mailbox address/domain to the allow-list, and confirm the conspicuous admin banner appears. Verify a non-allow-listed fixture is shown as suppressed and is not delivered; test mode restricts Parish Intake queue mail only.
- Release checklist: install zip → run migrations → send test emails (single event, bulletin, poster PDF, recurring event) → confirm via the emailed links → approve as a dean and as a reviewer → make a change as a verified contact and revert it → check the events page and ICS feed → check the health dashboard, that the 2-hourly xneelo cron and the external pinger both trigger jobs within the time budget, and that the mail queue respects the hourly cap.
- Remove the instance afterwards. Launch starts with a few pilot parishes.

## Running tests locally

With PHP and Composer installed:

```bash
composer install
composer test        # PHPUnit (tests/Unit) + prototype smoke test
composer test:unit   # PHPUnit only
vendor/bin/phpunit --group greenmail tests/Integration/Imap # requires the GreenMail service
composer fixture-score # Per-fixture and overall golden-field score
composer test:integration # WordPress integration tests; requires npm ci and a built release zip
```

The GreenMail group uses plain IMAP only in its explicit test configuration and delivers invented `example.test` messages over SMTP. The pinned GreenMail service has separate throwaway accounts for the adapter protocol test, connection-test service and mailbox-poller test, so test messages cannot interfere with one another and the wrong-password case exercises real authentication. The poller integration test archives prior messages in its own throwaway account and checks resume, content de-duplication, attachment storage, oversized-message handling and folder moves. Start the service and run the group using the Docker-network commands in the [development guide](development.md#local-setup-windows-no-php-install-needed). The tests live outside `tests/Unit`, so `composer test` does not start or require GreenMail.

`composer test` includes `CoreIsolationTest`, which tokenizes every PHP file under `src/Core/` and rejects WordPress function calls (`wp_*`, `esc_*`, `sanitize_*`, translation helpers such as `__()`/`_e()`, and the listed global WordPress APIs including `get_option`, `add_action`, `current_user_can`, and `dbDelta`), `WP_*` classes, WordPress constants such as `ABSPATH`/`ARRAY_A`, and WordPress globals such as `$wpdb`/`$wp`. It also catches `function_exists()` checks for those APIs. Comments and ordinary strings are ignored; PHP-native helpers such as `function_exists('mb_strtolower')` are allowed.

Without a local PHP, use the Docker commands in the [development guide](development.md#local-setup-windows-no-php-install-needed). CI (`.github/workflows/ci.yml`) runs `composer validate`, a `php -l` lint and `composer test` on PHP 8.2, 8.3 and 8.4 for every PR and push to `main`.

The integration suite runs against a disposable `wp-env` Docker environment and installs `dist/adct-parish-intake.zip` with WP-CLI. The raw repository checkout is not activated or used as the plugin. With Node.js/npm and Docker Desktop installed, run:

```powershell
npm ci
docker run --rm -v "${PWD}:/app" -w /app composer:2 sh scripts/build-release.sh
npm run test:integration
```

The event-listing check also seeds 150 fictional parishes and 1,800 occurrences. It measures cold/warm listing cost, exercises multi-type (including secondary assigned types), parish/deanery combinations and bookmarked paging, and checks that REST and no-JavaScript output reject oversized inputs and hide unpublished events and private contact data.

The test runner creates and stops its own isolated `wp-env` environment. Its test-only Docker data is retained under the system temporary directory for faster local reruns; CI runners discard it with the job. `composer test` remains the unit/smoke suite and does not start WordPress. CI (`.github/workflows/ci.yml`) runs `composer validate`, a `php -l` lint and `composer test` on PHP 8.2, 8.3 and 8.4 for every PR and push to `main`, plus separate WordPress and GreenMail integration jobs on PHP 8.2.

`wp-env` is used instead of the Playground CLI for integration tests because it supplies a normal WordPress/MySQL environment and WP-CLI in Docker. That lets CI install the exact release zip and exercise the admin menu/page without browser automation.

The PHP 8.2 CI matrix job appends the fixture-score table to the GitHub Actions step summary. The score command exits successfully for parser mismatches so it reports quality without hiding failures from the PHPUnit golden tests; it exits unsuccessfully only if a fixture cannot be loaded or parsed.
