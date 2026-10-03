# Testing

Tests protect the project from breaking as more people and sessions work on it.

**Rule: never remove or weaken a test just because the implementation fails it.** Fix the implementation. If a test's expectation really is wrong, change it in its own commit that explains why (e.g. "fixture expected US date order; SA uses DD/MM").

For pre-release schema changes, test the built zip on an isolated **fresh** WordPress database and assert the changed tables, columns and indexes; do not add a historical upgrade test solely for a disposable test installation. Keep existing migration tests. If existing data must survive, test its migration as well, even before release. After the first non-prerelease GitHub Release, test both fresh installs and upgrades for every schema change ([ADR 0016](decisions/0016-pre-release-schema-changes.md)). Never rebuild a live or persistent database to avoid writing a migration.

## Test layers

| Layer | What | Where it runs | When |
|---|---|---|---|
| 1. Unit | Domain core: parsing stages and directory lookup, date/recurrence rules, event details and RFC 5545 validation, RRULE expansion and rolling-window jobs, transactional occurrence replacement, schema migrations, matching and trust rules, action tokens, MIME/header parsing and automated-mail detection, bounded PDF text extraction and column-aware ordering, SPF/DKIM/DMARC handling, confirmation recipient safety and HTML/plain-text snapshots, queue idempotency, recovery before current-policy suppression after an uncertain marker write, and cross-recipient conflicts, token binding/thread headers, terminal raw-header failures and UTC markers, inbound reprocessing and sanitized retry diagnostics, Inbox status queries and draft replacement that preserves reviewed rows, IMAP protocol and polling checkpoints, source retry cadence, snapshot invalidation, review queue tab classification as a partition, and Core isolation. | GitHub Actions + locally | Every push / PR |
| 2. Parser fixtures ("golden" tests) | Fictional or safely anonymised parish emails (`tests/fixtures/emails/*.eml`) with expected output (`*.expected.json`). | GitHub Actions + locally | Every push / PR |
| 3. WordPress integration | Activation from the built release ZIP without PHP errors/notices; a fresh isolated install reaches schema v11 with all 19 `adct_pi_*` tables and the mail-queue composite unique index; simulated v3-to-v11, v4-to-v5 duplicate-preservation, v5-to-v11, v6-to-v11 and installed v7/v8/v9/v10-to-v11 migrations verify the final schema, preserve queue, contact and follow-up rows, create v7 confirmation metadata, the v8 approval-notice table and v9 ownership table, and add nullable v10 sender-suggestion and v11 follow-up parish fields; action-token GET/POST, nonce and renewal-limit checks; event type/taxonomy registration, occurrence rebuilds and rollback, REST metadata privacy and role authorization, public listing/block pagination and cache behavior, and uninstall capability cleanup; mailbox settings, polling, duplicate/skipped-message reporting, safe Inbox status/error rendering and same-row reprocessing without candidate, attachment, checkpoint, mail or privacy regressions; confirmation previews, safe recipient selection and suppression, cross-recipient queue recovery, bound action tokens and thread headers; receipt-owned Processed-folder cleanup leaves untracked mail untouched and fails closed without UIDPLUS; directory imports seed provisional defaults, linked outstation venues and official office-email sources idempotently; parish sources can be registered and switched official, health can be recorded, pending sender links remain opt-in disabled until an operator confirms them, and the Senders screen shows suggestion-only pending links with explicit confirm/block actions; the Sources submenu and parish Sources tab render; venue creation/default selection/location lookup work; and the Manual parser resolves a parish and default venue from a verified sender and stores the first candidate; the candidate detail screen previews a stored poster inline and creates a blank, approver-less `awaiting_approval` candidate from a hand-typed-event POST that then approves through the ordinary save route. | GitHub Actions using `wp-env` (Docker) and WP-CLI | Every PR |
| 4. IMAP integration | SMTP delivery followed by mailbox search, raw fetch, folder creation, seen marking and move against throwaway GreenMail; the polling job stores an attachment, resumes after an item-budget stop, de-duplicates a resend with a new Message-ID, records and moves an oversized message without blocking later mail, persists automated-mail flags and structured untrusted SPF/DKIM/DMARC verdicts, and moves successful messages to Processed; the mailbox test-connection service checks successful login, wrong-password and missing-processed-folder results, including source health updates. The test group is isolated from the default unit suite. | Separate GitHub Actions job with a pinned GreenMail service image | Every PR |
| 5. Manual preview | Click-through of admin/portal/approver/public UI. | WordPress Playground **PR preview button** (every PR); InstaWP / TasteWP with the CI-built zip for real mail | Every PR (Playground); as needed (InstaWP/TasteWP) |
| 6. Pre-launch check | Real xneelo PHP/cron/mail limits with a test mailbox. | A **temporary** staging instance on xneelo, removed afterwards (no permanent staging) | Once before launch; optionally before big releases |

`MigrationRunner` unit tests assert exact applied versions and high-water marks for fresh `0→11` and upgrade paths `6→11`, `7→11`, `8→11`, `9→11` and `10→11`, and reject a missing v11 registration. Installed-ZIP coverage verifies fresh schema v11 with all 19 tables, preserves existing contacts while adding the v10 suggestion fields, preserves existing follow-up rows while making `follow_ups.parish_id` nullable, and upgrades installed sites from stored versions 7, 8, 9 and 10.

The unit-test CI matrix runs PHP **8.2** (production), **8.3** and **8.4** as enforcing jobs. A **PHP 8.5** job runs the same lint and tests for information only: 8.5 is not a supported target, so it is `continue-on-error` and never blocks a PR. The WordPress integration job and GreenMail IMAP integration job run separately on PHP 8.2.

E1.3 sender-learning tests cover normalized exact-address retries, unlinked pending contacts with and without guesses, labelled signature/parser/body/domain suggestions and ambiguity, explicit confirmation to a chosen parish, blocked and multi-parish states, v9-to-v10 contact preservation, stored/decoded `From` mismatch failure before candidate/contact writes, and ZIP-installed WordPress intake/admin rendering with capability and email-bound nonce checks. Parish guesses stay suggestion-only until an authorized administrator confirms them, and confirmation never bypasses approval under ADR 0008.

Optional AI tests use fake HTTP and a fake call gate for accepted fields, hostile/invalid JSON, timeout, 429 cooldown and cap denial; no live model is contacted. The WordPress zip integration checks the non-free warning, that Manual parser never sends AI HTTP when enabled, and that the existing MariaDB atomic rate-limit bucket enforces the daily AI cap.

Event-type fixtures in `EventTypeClassifierTest` cover every starter type, title weighting, ties, no match, whole-word matching, custom types and keyword edits between pipeline creations. A two-event bulletin verifies that each candidate is classified from its own source block; synthetic AI enrichment verifies final recovered titles and descriptions are considered while explicit admin types are preserved. The built-ZIP `EventTypeCheck` exercises term-meta editing through a nonce-checked administrator action, versioned seeding without overwriting edits, and an existing Pilgrimage name with a customized slug. The inbound reprocess check asserts that candidate fields contain the selected type; publication checks assert the assigned type reaches the occurrence filter and later automatic updates preserve a manually assigned type. Tests use only invented `example.test` mail.

Issue #59 is covered by two layers. `ReviewQueueRepositoryTest` (unit, no database) takes the exact SQL `CASE` expression the repository sends to MySQL, reads its branches, evaluates them first-match against a synthetic candidate matrix with a small fail-fast interpreter, and asserts the assignment is a partition: every non-final candidate reaches exactly one tab, every documented status is claimed by a branch or deliberately scoped out by `where()`, no branch invents a status the schema cannot produce, the terminal `ELSE` still classifies a status nobody anticipated, every category label names a tab the queue renders, every category tab is filtered with a single equality, and one identical expression drives both the rows and their tab counts. The matrix rows are checked against a hand-written oracle derived from `docs/data-model.md`, so two independent derivations must agree. The ten statuses are read off the data model because `SchemaDefinitions` declares `status` as a free-form `varchar(32)` with no `CHECK` constraint, so the test also probes an invented status to prove the fallback. `awaiting_approval` is the one deliberate exception to "exactly one tab": it is a status-scoped overlapping view, asserted as such, and `recent_changes` stays a query-less placeholder. `tests/Integration/ReviewQueueCheck.php` covers the same partition against a real MySQL database — the disjoint primary categories alongside the overlapping all-items approval view, scoped tab counts, menu badge, sender/parish/title search, read-only preview and submitter view, conditional first-wins bulk decisions, unsafe-match exclusion, parish reassignment without sender verification, and dean/reviewer capability and nonce checks — plus one audit entry per bulk action actually applied, for approval, rejection and parish assignment. That file is layer 3: it runs only in the `wp-env` harness through the CI WordPress integration job, never in `composer test`, so a green local PHPUnit run is **not** evidence for it and a change to it can only be confirmed by that job. Instant-change and Revert integration was deferred to #71 and is still only partly delivered there: the revert handler is implemented and unit-tested, while the change-notice job, `event_changes.notified_at` and the admin change-history view remain outstanding. The queue never represents ordinary approved updates as instant changes.

Issues #43 and #130 are covered by three layers. `ConfidenceScoringStageTest` is the regression suite for the defect: it asserts that a field the parser invented is marked `unsupported`, scores 0, and lowers the overall score, while an equivalent-looking value actually read from the notice is not penalised. `FieldConfidenceTest` pins the scoring model itself — the origin table, its ordering, the weakness-flag penalties, clamping, the coverage weighting, the separate charge for a missing required field, the `unsupported` penalty, whole-candidate penalties, and the unweighted-field exception. `ConfirmationEmailRendererTest` asserts the submitter-facing behaviour: a fabricated field is marked for checking in both the HTML and the plain-text preview, well-evidenced fields are not marked even when the overall score is weak, and a field with no recorded score is left alone. The `fabricated-block` fixture is the end-to-end case: a bulletin where the parser falls back on a body fragment, so its second candidate carries a plausible `parish_name` that the expected JSON asserts as `unsupported` with score 0, and the message is classified `low_confidence` rather than published.

`CandidateFieldSetTest` and `CandidateEditValidatorTest` (unit) cover the #61 edit surface with no WordPress present: the editable-key allow-list, day-first `DD/MM/YYYY` to ISO conversion including a strict rejection of rolled-forward dates such as `31/02/2026`, unchanged-value detection, required-field errors, the recurrence preset/ordinal/weekday/custom combination that produces a validated RFC 5545 rule, and the `CandidateEditResult` that distinguishes an invalid form, an unchanged save and a real edit.

Issue #54 adds Core date-window validation and release-ZIP WordPress integration coverage for the public shortcode and server-rendered block, pagination through the 1,800-row fixture (including pages 90–91), invalid dates, cache invalidation and private-post suppression. Custom date ranges must not create transients; preset cache keys are fixed by period and page. Its deterministic load fixture seeds 150 fictional parishes, 150 published posts and 1,800 occurrence rows spread over the next year. A cold 20-card **upcoming** listing must finish in **5 seconds or less with at most 100 SQL queries**; a cached repeat must finish in **2 seconds or less** in the isolated CI WordPress environment. These thresholds reserve at least 85 seconds of xneelo's 90-second PHP request budget and prevent a per-occurrence query pattern. Timings include rendering, not fixture creation; they are regression guards rather than a production SLA.

Issue #57 adds deterministic RFC 5545 output tests (CRLF, UTF-8 octet folding, SAST VTIMEZONE, stable UID, RRULE/EXDATE/RDATE and UTC UNTIL, all-day exclusive end and cancellations). `IcsFeedLinksTest` (unit, no WordPress) covers the subscribe block's decisions: label wording, parish scoping, term-slug mapping, splitting a multi-type filter into one feed per type, unknown and duplicate term ids, the `MAX_LINKS` cap and `webcal:` rewriting. The installed-ZIP test checks parish/type filtering, a cold filtered-feed budget of at most 15 SQL queries, private-event exclusion, privacy, ETag invalidation, and the listing's subscribe block (the unfiltered feed link and its `webcal:` twin, the correctly scoped URL and label on a parish+type-filtered listing, plain/`webcal:` pairing, and no `adct_ics` leaking into filter or pagination links). The rendered output is also validated outside the plugin with Python `icalendar` — CRLF endings, no line over 75 octets, no surviving control characters, round-tripped escaping and `TZID`/all-day parameter forms. Issue #60 extends the installed-ZIP suite with published-only single-event pages, structured data shape, recurrence phrasing, next dates, poster/contact handling, and per-event ICS/Google Calendar links. Google, Apple and Outlook subscription checks remain **pending** until an authorized test site is available; no live site is contacted for this feature.

The WordPress integration harness uses a stable, distinct `WP_ENV_HOME` per checkout by default. For parallel runs, set `WP_ENV_HOME` to a new absolute directory and choose free `WP_ENV_PORT` / `WP_ENV_TESTS_PORT` values; never point it at an environment another session is using. The harness retains a generated `.wp-env.json` with absolute repository mappings in an ignored cache directory keyed by the resolved home and repository config. Reusing the same home with unchanged config reuses that isolated `wp-env` project and its Docker volumes; choose a different home for a fresh database. Local runs leave their environment running by default; CI stops only the environment whose start succeeded. Set `ADCT_PI_KEEP_WP_ENV_RUNNING=1` to keep it running in CI, or `0` to opt into stopping locally only after confirming no other session is using it. The harness never deletes Docker volumes, the caller's home, or a config it cannot verify. Run `npm run test:integration-config` to check home and config isolation without starting Docker.

Issue #48 coverage includes option-backed test mode defaulting off, exact address/domain matching, fail-closed empty and malformed configuration, admin banner and preview authorization/escaping, no mail-preview REST route, and suppression of a row queued before settings change. The integration test's `pre_wp_mail` callback exists only around synthetic queue delivery and rejects anything outside `example.test`; production code does not install a global mail filter.

`ActionTokenPurposeReservationTest` (unit) pins which action-token purposes are usable and which are placeholders. It builds the real handlers to learn the handled set, reads `Plugin`'s registration block to check the mirror has not drifted, and fails on any case that is neither handled nor listed in `RESERVATIONS` with an owning issue, on a reservation whose value is no longer a case, and on a reservation whose issue number is absent from the enum comment or from the `purpose` documentation in `docs/data-model.md`. `ActionTokenEndpointUnhandledPurposeTest` (unit) covers the reserved purposes end to end through the endpoint: the GET and the nonce-protected POST both return the "not available" page with no form, log exactly one `error_log` line naming the purpose, and leave the token valid, while a POST with a bad nonce is refused before the purpose is read and logs nothing. WordPress stubs for the endpoint test live in the test file's own namespace block, following the `ParserPageTest` pattern.

E4.7 unit tests cover the outbound queue's rolling cap across runs, atomic in-flight reservations, priority ordering (including a login link ahead of 200 digests), per-recipient group-key idempotency, exponential retries and terminal failure, retry after an interrupted claim, unknown delivery exceptions retaining reservations, and allowlist suppression without delivery or cap usage. WordPress integration tests verify the sender job wiring and database-backed queue state, including a login link queued behind 200 digests; a `pre_wp_mail` interception accepts only fake `example.test` recipients so the test never sends real mail.

See [ADR 0009](decisions/0009-preview-and-test-environments.md) for why previews and test sites are set up this way.

## Planned approval flow test cases

The installed-ZIP `ApprovalDecisionCheck` exercises grouped dean/reviewer notifications, a read-only GET, nonce validation, a competing decision, winner replay, reviewer-only routing, edits without publication, rejection with an escaped reason, daily digest, ambiguous-match manual routing, suppression and queue idempotency. `ReviewQueueCheck` combines dean-only visibility, a dean's first decision against a competing reviewer action, and an ambiguous candidate that remains undecided when either a scoped dean or reviewer tries to approve it but remains rejectable. It also verifies that a failed email-link publication is retryable by the dean's active assignment when its email differs from the WordPress account email, without writing a duplicate decision audit. The queue UI withholds ordinary approval selection and its button for an ambiguous awaiting item; the bulk handler rejects the manual-review selection. Existing `MailQueueServiceTest` checks that both priority-2 notices and priority-3 digests use the rolling hourly cap and retry/backoff dispatcher. The integration check enables queue test mode with invented `example.test` addresses; it does not dispatch real mail. To run only these cases against an already activated installed ZIP in the isolated wp-env CLI: `wp eval-file /var/www/html/wp-content/test-harness/approval-only.php`.

`CandidateDetailCheck` covers the #61 candidate detail screen against the installed ZIP. It asserts that the screen renders the source subject, sender, body and attachment list, every stored field, the recurrence rule and the audit trail, that a hostile stored description is escaped rather than emitted raw, and that both download forms carry their nonces. It then drives the handlers: a parish contact without review capability is refused, an invalid or missing nonce is refused, an attachment belonging to another candidate is refused, and an out-of-scope candidate is refused. Save is exercised for an invalid nonce, an out-of-scope candidate, a decided candidate, a re-rendered invalid form, a valid change, an unchanged resubmission and Save-and-approve; a second decision on a decided candidate is refused. Repository-level assertions cover the `update_fields` audit details, which record only the keys that actually changed, and the day-first `DD/MM/YYYY` value being stored as ISO. To run only these cases against an already activated installed ZIP in the isolated wp-env CLI: `wp eval-file /var/www/html/wp-content/test-harness/run-candidate-detail.php`.

`CandidateDetailCheck` also verifies that the editable screen retains the readable per-field confidence table and labels unsupported values as guesses, alongside the source, edit controls and protected downloads.

`AuditLogCheck` covers the #58 audit log screen against the installed ZIP. The unit tests prove the screen renders and that the repository builds the right SQL; only the harness can prove the two fit together, so this check writes a row through the same repository the screens write to, then reads it back through `Plugin::auditLog()`: the actor, action, subject, subject ID, details and time survive the round trip, and the count and the row list agree. It then asserts the filters (`actor`, `subject_type` plus `subject_id`) narrow and exclude correctly, that a 26-month window is refused against the 24-month retention horizon, that the screen renders a row written with email-supplied details without emitting that text as markup, that a subscriber without the reports capability is refused, and that the rendered HTML contains neither `method="post"` nor a `_wpnonce` — the two properties that make the screen read-only. It also asserts the screen is registered under the Parish Intake menu. Everything it writes belongs to one invented `audit-check@example.test` actor and is deleted in a `finally` block, so an interrupted run cannot leave a row behind to be counted by the next one.

`AuditLogPageTest` covers the screen without a database, including the escaping of every rendered field and the absence of any POST handler. `AuditLogRepositoryTest` covers the SQL, the hard limit and offset bounds, and the requirement that `created_at` has the exact `!Y-m-d H:i:s` shape. `AuditQueryTest` covers the window and paging bounds, and `AuditActionTest` fails if any action literal is written anywhere but the enum, so the screen's dropdown cannot drift from the catalogue.

Issue #63's manual entry is covered at both layers. `ReviewQueuePageTest` (20 unit tests) drives `handleCreateManual()` against a recording `$wpdb`: the button is rendered only for a still-stored image and not for a PDF, a deleted file or a decided candidate; the form carries its own action and `manual_nonce`; and the handler refuses an invalid nonce, a missing or zero candidate id, an out-of-scope candidate, an already-decided source, a non-existent attachment, and an attachment id belonging to a different message. The happy path asserts the insert itself — the new row is anchored to the candidate's *own* message and parish rather than to whatever the request happened to carry, takes the next free `block_index`, is inserted with only `message_id`, `block_index`, `parish_id`, `fields`, `confidence`, `parser_version`, `notes`, `match_kind`, `status` and the timestamps, and **never** with `approved_by`, `approved_via`, `approved_at`, `decided_by` or `decided_at`. `ReviewQueueManualCandidateTest` (11 unit tests) covers `createManualCandidate()` directly, including the block-index collision under concurrency. Two negative tests in `CandidatePublisherTest` prove the allow-list itself still refuses a hand-typed candidate: a `status = 'approved'` row with `approved_via` outside `dean`/`reviewer`/`self` is not published and is not self-published.

`CandidateDetailCheck` extends the same story against the installed ZIP with a real database: the image is previewed inline beside the form, exactly one **Create event from this poster** button appears on an email carrying both a PDF and an image, the button is a POST form rather than a link, the created row carries the source message and parish with `status = awaiting_approval` and every approver column null, the redirect points at the new row with `created=1`, and that row then goes through the ordinary `handleSave()` approve path — where `approved_via` is non-null and not `self`. As with the rest of `tests/Integration/**`, this file runs only in the wp-env job, so a green local run says nothing about it.

`CandidateEditFormTest` locks the detail form's recurrence vocabulary to `RRulePresetMapper`'s: every preset, ordinal and weekday the form offers must be one the mapper accepts. This check exists because the two vocabularies were once out of step, which made every recurrence edit fail validation with an error the reviewer could not act on.

The route-resolution subset is covered by #68: unit tests exercise two active approvers, a parish without a deanery, no active approvers (including an inactive assignment), and an inactive deanery. The WordPress integration suite also assigns two approvers to a deanery, resolves routes for its parishes, checks reviewer-only behavior without a deanery, and verifies role retention and removal.

`ApprovalNoticeJobTest` additionally covers the digest send-hour gate for #69: the gate opening exactly at the configured local hour and staying shut before it, a rejected out-of-range hour, a per-item approver being notified before the digest hour, a digest approver being held with no notice row, no mail and a completed run so the candidate is re-read later, and a digest approver being notified with the per-recipient local-date group key once the hour has passed. The WordPress user, escaping and URL helpers these cases need are stubbed in the test's own namespace block, following the existing `ParserPageTest` pattern. `ApprovalReminderJobTest` shares that same stub block rather than redeclaring it, and covers the three acceptance criteria for #70 directly: a reminder is queued exactly once once an item has waited the configured period, no reminder is queued when the global switch or that approver's own switch is off, and no reminder is queued once the item has been decided. It also covers the batch limit, the daily `monitoring` interval gate, the fail-safe behaviour of an unusable stored period, and the fact that a repeat run does not queue a second reminder for the same item and approver. `FollowUpRepositoryTest` covers the two placeholder shapes needed for a candidate whose parish has been unlinked, and `FollowUpParishNullableMigrationTest` covers the v11 upgrade preserving existing rows. End-to-end event lifecycle cases remain planned alongside the approval work ([ADR 0008](decisions/0008-approval-by-dean-or-archdiocese-reviewer.md)):
- A new event from a verified contact doesn't publish after confirmation alone. It goes to `awaiting_approval`.
- An awaiting item is visible to the parish's deanery approvers **and** to archdiocese reviewers, and not to approvers of other deaneries.
- First to act wins: two approvals (or an approve and a reject) for the same item leave exactly one decision. The second action gets "already decided".
- Self-approval: a dean submitting for a parish in their deanery, or a reviewer submitting anything, publishes on confirmation with `approved_via = self`.
- A dean submitting for a parish **outside** their deanery still needs approval.
- A matched change, cancellation or postponement candidate with `match_review_required = true` stays in approval; it never self-publishes.
- A change or cancellation by a verified contact to a published event publishes immediately, writes `event_changes`, and notifies approvers. Revert restores the previous version.
- A change from an unknown sender or a monitored source needs approval.
- A group without a deanery goes to reviewers only.
- A parish in a deanery with **no active approver** (dean not set up) goes to reviewers only, is approved by a reviewer, and the dashboard lists that deanery as "no approver".
- An unchanged repeat of a published or pending event (same parish, title, schedule) is marked `duplicate` and sends **no** confirmation or approval email.
- Approver digest mode sends one daily email instead of one per item. Reminders respect the on/off switches.
- Action links: GET renders only a safe preview; nonce-protected POST consumes a token once; invalid, expired and used tokens have friendly states; renewal is queued without exposing recipient data in the page; and a failed self-publication can be retried safely with the same token because the confirmation decision may already be recorded. MariaDB integration tests verify that two concurrent conditional token-consume updates admit exactly one winner, same-second N+1 request denial for both per-email and per-IP limits, and no additional queue delivery after the cap. E4.3 integration checks submitter decisions, confirm-all, rejection reason, audit flags, self-publication for assigned deans/reviewers, wrong-deanery and spoofed Reply-To safeguards, suppressed previews, ambiguous pending matches, and change/cancellation/postponement items staying in review. `ConfirmationRoutingCheck` adds the end-to-end submitter-to-approver routing paths listed below. CandidatePublisher unit coverage rejects ambiguous and pending-candidate matches; ApprovalNoticeJob unit and installed-ZIP checks suppress fields-only pending matches before queuing notices/tokens, and the installed-ZIP approval handler check rejects a stale flagged-candidate approval before token consumption or decision while preserving rejection and genuine winner-token recovery. Existing winner-token replay checks preserve post-commit retry behavior.

### Confirmation-to-approval routing paths (E4.3)

`ConfirmationDecisionCheck` proves that a submitter's own decision is recorded and `ApprovalDecisionCheck`
proves the approver's side, but neither walks a submitter's confirmation *into* the approval queue.
`ConfirmationRoutingCheck` drives that whole chain against the installed release ZIP — the mailed
confirmation link through `ActionTokenEndpoint`, then `ApprovalNoticeJob`, then the emailed approver link
— so the ADR 0008 routing table is covered without a browser. It first pins route resolution for all four
reasons (`REASON_OK`, `REASON_NO_DEANERY`, `REASON_NO_ACTIVE_APPROVER`, `REASON_DEANERY_INACTIVE`,
including deactivating and reactivating an appointment), then walks these paths. Each `$check` message is
prefixed `Path N:`, so a red run points at one route rather than one opaque boolean.

| Path | Scenario | Asserted outcome |
|---|---|---|
| 1 | A plain submitter confirms for a parish in an active deanery. | Candidate goes to `awaiting_approval` with no approver; each active dean and each archdiocese reviewer gets one priority-2 email under `approval:<id>:<hash24>` carrying Approve/Reject/Edit; the dean's mailed approval publishes once as `approved_via = dean`. |
| 2 | The reviewer's copy is opened after the dean approved. | Preview titled *Event already decided* naming the winner, POST answers 409, the dean's decision is unchanged. |
| 3 | A reviewer approves from their own mailed link. | Published with `approved_via = reviewer`. |
| 4 | The submitter denies, with a reason, then replays the link. | `rejected` with the `decision_note`, one `submitter_denied` audit row, no publication, zero approval notices, replay 409. |
| 5 | An address with no verified contact confirms. | `unknown_sender` recorded on the candidate, `WARNING: Unknown sender. Verify the parish before approving.` in the approver mail, still approvable. |
| 6 | A message reporting a DMARC failure confirms. | `dmarc_fail` recorded, `WARNING: Reported DMARC failure.` in the approver mail, still approvable. |
| 7 | An address linked as blocked elsewhere confirms. | Never self-approves, even as the appointed dean of the parish, but is still a routed approver and publishes through their own mailed link. |
| 8 | A suspended dean confirms. | Never self-approves and is skipped by the notice job, while the other dean is still notified. |
| 9 | A parish in a deanery with no active approver. | `REASON_NO_ACTIVE_APPROVER`; reviewers only; a reviewer approves it as `approved_via = reviewer`. |
| 10 | A parish with no deanery. | `REASON_NO_DEANERY`; reviewers only; the reviewer still approves it. A dean of another deanery holding a hand-issued token is refused twice: the endpoint answers 200 with the "This link is not valid" page (`ActionTokenEndpoint::respondToAction()` rejects an approver whose `preview()` is `null` before `performAtomic()` ever runs), and a direct `performAtomic()` call asserts the handler's own refusal, `This approver is no longer assigned.`, with the token still `VALID`. |
| 11 | A parish in a deanery whose status is not active. | `REASON_DEANERY_INACTIVE`; reviewers only; a reviewer approves it. |
| 12 | A `match_kind = update` candidate. | Stays `awaiting_approval`, never self-publishes, routed to reviewers and not to the parish dean. |
| 13 | The appointed dean submits for their own parish. | Publishes once on confirmation as `approved_via = self` with `decided_by` null, one `source_candidate_id` postmeta row, zero approval notices. |
| 14 | The permalink is unreadable when a self-approval commits. | 503 with the "retrying is safe" wording, the token `USED` and the candidate already `published`. `recover()` is then called **directly** on the handler: replaying the link over HTTP cannot reach it, because `performAtomic()` leaves the row at `published` (or `rejected`), `candidates()` filters on `status = 'draft'`, so `preview()` returns `null` and the GET renders the invalid-link page. The direct call answers 200 *Your event has been approved and published.* and leaves exactly one postmeta row. |
| 15 | A second appointed dean presses their link, then presses it again. | Publishes as `approved_via = dean`. The replay is a **200** by design, not a 409: `ApprovalDecisionHandler::preview()` keeps the winner's own link actionable so they can read the outcome, so the page reads *Already approved by …* naming the winner, the candidate and post count are unchanged, and there is a single `approver_approved` audit row. |
| 16 | An approver is withdrawn between the notice and the click. | The endpoint answers 200 with the "This link is not valid" page, because a withdrawn approver has no `preview()` and the endpoint rejects that before `performAtomic()`. A direct `performAtomic()` call then asserts the handler's refusal `This approver is no longer assigned.`; the candidate is still `awaiting_approval` and there is no `approver_approved` audit row. |
| 17 | A denial link is used to recover. | `There is no self-approval to complete.` |
| 18 | A confirmation that went to approval is used to recover. | `This confirmation has already been completed.` |

**Why two paths assert a 200 and a refusal separately.** `ActionTokenEndpoint::respondToAction()` checks
`$handler->preview($binding) === null` *before* calling `performAtomic()`, so for an approver who has been
withdrawn, suspended or never appointed, the endpoint's own guard answers 200 with the "This link is not
valid" page. The `DomainException` that the handler would raise for the same approver is therefore
unreachable over HTTP. Paths 10 and 16 assert both halves honestly — the endpoint's real user-visible
answer, and the handler's own refusal reached by calling `performAtomic()` directly — rather than asserting
a status code the endpoint can never return. Paths 14 and 15 are the mirror image: `recover()` is called
directly because a spent confirmation token can never be routed to it, and the deciding dean's replay is a
200 because `preview()` deliberately keeps the winner's own link actionable.

### MVP demo steps 3 and 6 — owner-run checklist on a live test site

Backlog demo step 3 is *"She confirms both. The dean of her deanery and the archdiocese reviewers each get
an approval email. The dean approves from the email. The reviewers' copy now shows 'approved by the
dean'."* Step 6 is *"An email from an unknown address goes through the same confirm-then-approve steps.
The approver sees an 'unknown sender' warning and links the address to a parish."* Both need real mail on a
temporary InstaWP/TasteWP site ([ADR 0009](decisions/0009-preview-and-test-environments.md)) and are
**not** automated. `ConfirmationRoutingCheck` asserts the same routing against the installed ZIP; what it
cannot assert is real SMTP delivery, real mail-client rendering of the links, and the reviewer actually
performing the Senders link. An agent does not provision test sites or send real mail, so an operator runs
this. Every address used must be a throwaway test mailbox. Never use a real parish address, and never copy
a real bulletin into a test site.

**Setup (once per run)**

1. Create a throwaway site on InstaWP or TasteWP and install `dist/adct-parish-intake.zip` by URL (the
   artifact from the `pr-preview-build` job on the branch under test).
2. **Parish Intake → Outbound email**: switch **Test mode** on and allow-list only the test mailbox, as an
   exact address (`events-test@example.test`) or exact domain (`@example.test`). Confirm the admin banner is
   visible. Everything else must stay **suppressed**; check **Outbound email → Suppressed** to confirm.
3. Add three WordPress accounts, each with the address that is allow-listed:
   - the parish secretary — no intake capability, and a `parish_contacts` row with `trust = verified`;
   - the dean — role **Deanery approver**, assigned to the secretary's parish's deanery via
     **Parish Intake → Deaneries** (notify mode *each*);
   - one **Intake reviewer**.
   Record each address; these are the addresses you will check mailboxes for.
4. **Parish Intake → Mailboxes**: connect the test mailbox so real mail arrives. If the external pinger is
   not running, expect up to 2 hours ([ADR 0010](decisions/0010-scheduled-jobs-with-2-hour-cron-limit.md));
   otherwise roughly 15 minutes.

**Step 3 — confirm, route to dean and reviewers, dean approves**

1. From the secretary's address, mail the intake mailbox **two events in one notice**: one once-off and one
   "every first Friday". Use invented content only.
2. Expect one confirmation email listing both events exactly as they will appear.
3. Open the confirmation link in a **real mail client** (test in Outlook, Gmail and one mobile client).
   Record whether the Approve/Deny buttons render as buttons or bare links and whether the event details
   are readable. Press **Confirm**.
4. Expect the success page *"Thank you, your dean or the archdiocese will approve it shortly."*
5. Expect **two separate approval emails**: one to the dean and one to each Intake reviewer. Each must
   contain `Parish:`, the event title, date, time, venue and `Submitted by:`, followed by **Approve**,
   **Reject** and **Edit** links. Note the order.
6. Dean opens their **Approve** link. Record the preview page (title *Review event*, `Parish:`, the event
   fields, `Submitted by:`), then press the button. Expect the success page and a **View published event**
   link that resolves.
7. Open the **reviewer's** copy of the link. Expect the page titled *Event already decided*, naming the
   dean's address, and expect pressing the button to be refused rather than reversing anything.
8. Both events must appear on the public events page and in the ICS feed, and the recurring event must
   list its upcoming dates.

**Step 6 — unknown sender**

1. From an address with **no** `parish_contacts` row, mail the intake mailbox a single invented event.
2. Expect the same confirmation email, and confirm it normally.
3. Expect the approver's approval email to carry the line
   `WARNING: Unknown sender. Verify the parish before approving.` and **not** to publish on confirmation.
4. The approver approves it through the mail link as normal.
5. Link the address to a parish: **Parish Intake → Senders** (or **Parishes → Contacts**), confirm the
   pending link against the right parish, then send a second notice from the same address. It must arrive
   with **no** unknown-sender warning and, as a verified contact, update an existing event immediately with
   a change notice and **Revert** link to the dean ([ADR 0008](decisions/0008-approval-by-dean-or-archdiocese-reviewer.md)).

**Stop conditions and what to record**

Stop and file an issue rather than working around it if: no mail arrives within the expected window; a
confirmation or approval email is delivered to an address that is **not** on the allow-list; the Approve
or Edit link is missing or dead in any client; a confirmation alone publishes anything; the dean's approval
does not publish; the reviewer's copy can reverse the decision; or the unknown-sender warning is absent.

Record, per step: the site URL, the three test addresses, the arrival time of each email, the exact wording
of each screen, each mail client tested, and anything suppressed. Attach screenshots with real personal
details removed. Then delete the site and the test mailbox.

**Also required before launch:** run both steps on the temporary xneelo staging instance as part of the
[pre-launch check](#pre-launch-check-on-a-temporary-staging-instance), because InstaWP/TasteWP cannot
exercise the real 90 s PHP limit, the 2-hourly cron or the 500-recipients-per-hour mail cap.

## Scheduling and mail limits test cases

From [ADR 0010](decisions/0010-scheduled-jobs-with-2-hour-cron-limit.md) and [ADR 0011](decisions/0011-outbound-email-queue-with-hourly-cap.md):
- Unit tests cover the time and item budgets, a checkpoint saved after every item, resumption from incomplete mailbox scans, per-source polling intervals, immediate polling for never-checked sources, failed-poll retry backoff, the bounded inbound-message processing job, due checks, overlapping-run prevention, expired-lock recovery, stale-token release protection, and per-run lifecycle reset for stateful jobs. Occurrence-job tests also verify that an incomplete batch keeps a fixed window and resumes at the next published event.
- Retention tests cover opt-in validation, future scheduled runs after a terminal `done` checkpoint, bounded raw/token/audit batches, bounded UID windows, and processed-folder cleanup restricted to exact persisted `COPYUID` receipts matching source, mailbox identity, folder and UIDVALIDITY. Messages without receipts and unrelated/untracked folder mail are never deleted; UIDVALIDITY changes discard stale ownership, and cleanup without UIDPLUS fails closed. A scripted IMAP transport and temporary protected directory verify bounded removal, preservation of newer/in-progress files, repeat runs and checkpoint resumption. Installed-ZIP coverage exercises the real database adapter with disposable rows and private files, then removes all fixtures. These tests never connect to a real mailbox; do not run retention manually against production.
- Unit tests verify that exceptions release the lock and record the last error, and that `last_success_at` advances only after a successful batch.
- The WordPress adapters are checked for non-autoloaded state, option-backed lock creation, expiry and malformed-lock recovery, token-guarded release, action-token hash-only persistence and atomic consumption, and fail-closed rate-limit decisions independent of affected-row semantics.
- WP-Cron scheduling and cleanup failures are logged without escaping the scheduler; unexpected cron callback failures are logged and recorded when job state can still be saved.
- WordPress integration tests cover the custom ten-minute cron hook, deactivation cleanup, Run now capability/nonce flow, mailbox polling and occurrence job scheduling, schema upgrades through v11 including v6/v7/v8/v9/v10-to-v11 paths, the v4-to-v5 unique mail-index upgrade, and the v5-to-v11 rate-limit migration on MariaDB.
- The scheduled-jobs page reports per-job state. The health warning after 2 h 15 min remains a future health-dashboard behavior.
- Mail queue: the successful-recipient cap is enforced across runs with reservations for concurrent sends; priority 1 goes before priorities 2–3; identical composed per-recipient notices are idempotent by `group_key`; changed content under the same key errors; retries use exponential backoff and stop after 5 attempts; suppressed allowlist recipients never reach `wp_mail()` or count against the cap.
- A crash or thrown delivery exception with an unknown outcome holds its reservation for 60 minutes before retry. This protects the cap, but if SMTP accepted the message before the process died, that retry may deliver one duplicate. The unit tests cover the reservation window and retry.

## Parser fixture corpus

Real samples reviewed so far, and what they taught us, are in [parser findings](parser-samples.md). The originals are kept privately (not in this public repository).

- Collect real examples: bulletins, posters (PDF/image), one-line notices, forwarded emails, replies with quoted text, recurring schedules, cancellations, changes.
- The current fixture corpus is **entirely invented**; nothing derived from a real attachment has been added yet, so the issue's "10 anonymised real samples" criterion is neither satisfied nor claimed. The private originals are never opened in, copied to, or sent from this public repository or an automated agent.
- Deriving a fixture from a real sample is the expected route to that criterion, not a fallback. Follow the [fixture anonymisation guide](fixture-anonymisation.md), which covers reducing content, rebuilding headers and re-creating attachments. Replace personal names, phone numbers, personal email addresses, bank details and private addresses. Replace names in sick lists and Mass intentions but keep their headings. Parish names, public church addresses and `@adct.org.za` office addresses must also be replaced in this public repo. A person verifies the result before it is published.
- Invented recurrence fixtures cover an inferred first-Friday anchor, a Tuesday/Thursday weekly rule, a nine-day novena, and an ambiguous Advent schedule. Unit tests cover the other supported phrases, verify every emitted rule with `RRuleValidator`, and check that seasonal wording does not invent dates or an RRULE.
- Before a person adds any real-derived example, follow the [fixture anonymisation guide](fixture-anonymisation.md). Replace personal names, phone numbers, personal email addresses, bank details and private addresses. Replace names in sick lists and Mass intentions but keep their headings. Parish names, public church addresses and `@adct.org.za` office addresses must also be replaced in this public repo.
- Cover the structures described in [parser findings](parser-samples.md): text-layer and multi-church bulletins, printed-email style notices, text posters, image-only posters, forwarded messages, replies with quoted text, recurring schedules, date ranges, cancellations/postponements, and administrative notices.
- Each fixture is a pair: `tests/fixtures/emails/<name>.eml` and `tests/fixtures/emails/<name>.expected.json`. A fixture may set `directory_snapshot` to a JSON file in `tests/fixtures/` (the invented directory is shared from `tests/fixtures/directory.json`) to test parish, venue and sender resolution. Existing top-level parse fields continue to compare against the first/primary `ParseResult`. Change-notice fixtures can supply `existing_event` (a fictional published event with `id`, `parish_id` and `fields`) plus a directory snapshot that resolves the incoming parish; only those fixtures evaluate `match_kind` through `EventNoticeMatcher` and `event_status` through `CandidatePublisher` with a simulated reviewer approval and in-memory publication store. These are not parser output fields or a real publication. Bulletin fixtures may additionally provide `candidate_count`, a `candidates` list and `blocks` metadata; each candidate can assert `block_index`, `fields` (including title/date/time and directory match provenance), and other `ParseResult::toArray()` values. Skip metadata contains only a block index, `classification: skipped` and a category reason; never add skipped source text to expected output. A missing expected key is not checked; list lengths are checked. `known_failures` maps an output path (or parent path) to an existing issue. Mismatches covered by known issues are reported as incomplete; any untracked mismatch fails with a per-field diff. Keep the fixture and update the expectation only when the relevant issue is fixed.
- Every `.eml` must have a `From` header and a `Date` header with an explicit timezone so fixture metadata and relative dates stay deterministic. `EmailFixtureLoader` delegates to the production `Core\Ingestion\MimeMessageParser`, so fixture tests exercise real MIME transfer/charset decoding, alternative/related/mixed parts, HTML-to-text conversion, and quote/signature/newsletter cleanup. Attachment metadata and MIME part references are checked; attachment bytes are not extracted by the fixture loader. PDF text extraction is tested separately against re-created PDFs in `tests/fixtures/pdfs` (single column, two column, three column, image-only, and over-page-limit) generated by `tests/fixtures/pdfs/generate.php`, covering size, page and time limits and column-aware ordering.
- The runtime MIME parser is pure PHP and does not require `ext-imap` or `ext-dom`. Added invented fixtures cover Outlook HTML, Gmail and Apple Mail alternatives, mobile signatures, forwarded and quoted replies, Windows-1252, a Mailchimp-style newsletter, and bulletins containing every configured non-event section category beside real event examples. Unit tests verify all categories, weekly Mass-times tables, section boundaries, custom/sanitized keyword lists, and that skipped text is absent from outcomes and AI-provider input. Invented fixtures use names like `Example Parish`, `example.test` addresses and only fictional phone numbers in the `021 555 01xx` range. No fixture — invented or derived — may contain real personal, parish or bank details.
- A skipped section containing a strongly event-like line immediately after a blank line now emits only a generic `possible_missed_event_after_skipped_section` count and a Manual parser warning for review. The section remains skipped, including from AI input; this is a conservative diagnostic, not permission to publish its text. Real anonymised samples are still needed before changing the skip boundary (#98).
- To add a fixture, add those two files, then run `composer test` and `composer fixture-score`. The PHPUnit provider discovers fixture pairs automatically, and both runners apply the optional directory snapshot. Bulletin examples should assert all candidates with `candidate_count` and `candidates`; single-event fixtures may keep their existing top-level fields unchanged.
- A **score report** (fields right / total) is printed in CI. When parsing rules improve, the score should not go down.
- Every parser bug report should add a fixture first (failing), then the fix.

### Tracked fixture failures

These fixtures currently report a mismatch, so their test run is **incomplete** rather than green. Each
one is paired with a synthetic input that reproduces a real defect. When the issue is fixed, update the
expectation and delete the `known_failures` entry in the same change; the fixture then guards the fix.

| Fixture | Issue | Mismatch |
|---|---|---|
| `image-only-poster` | [#63](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/63) | `reprocess_needed` |
| `relative-date-coming-weekday` | [#132](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/132) | `fields.event_date` — "this coming Saturday" yields no date |
| `relative-date-end-of-month` | [#132](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/132) | `fields.event_date` — "end of this month" yields no date |
| `relative-date-first-of-month` | [#132](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/132) | `fields.event_date` — "the first of October" yields no date |
| `time-range-dotted-am` | [#153](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/153) | `fields.event_time` reads `00:00`; `event_end_time` missing |
| `time-range-dotted-bare` | [#153](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/153) | `8.30-10.00am` yields the range end only |
| `time-range-bare-hyphen` | [#153](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/153) | `7-9pm` yields the range end only |
| `real-format-ordinal-and-bare-time` | [#165](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/165) | `fields.event_time` — compact `830am` yields no time |
| `unheaded-event-after-sick-list` | [#98](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/98) | event after the skipped section is dropped entirely |
| `unheaded-event-after-deceased` | [#98](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/98) | event after the skipped section is dropped entirely |

Five of these nine share a cause: the value is dropped **silently**, with no error and no flag
([#167](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/167)). Fixing the individual format
bugs does not close that gap, because the next unrecognised format will fail the same way.

Current `composer fixture-score` baseline: **348/373 fields correct (93%)**, with 10 tracked failures
above.

**A falling total is expected. A falling pre-existing row is a regression.** The total percentage is the
number people look at first, and it will read as a regression even when nothing is wrong, because every
tracked fixture deliberately lowers it — each asserts the *correct* behaviour for a parser that is still
broken, so it scores low on purpose. Judge a change by the per-fixture rows instead:

- **Regression** — a fixture that scored 100% before now scores less, or a fixture that passed now reports
  an *untracked* mismatch and fails the build. Fix the code; never adjust the expectation.
- **Not a regression** — the total drops because a new fixture was added to the ledger, or because a
  tracked fixture gained a tracked failure. Both are visible in the table above.

When a tracked issue is fixed, its fixture goes back to 100%, the total *rises*, and the matching row is
deleted from the ledger in the same change.

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

**Pending for E4.2:** visually inspect the confirmation preview in Outlook, Gmail and on a mobile device using a temporary InstaWP/TasteWP site with Test mode enabled and only a dedicated test inbox allow-listed. No external site or mailbox was provisioned and no email was sent as part of this implementation; the local HTML/plain-text snapshots and intercepted WordPress integration delivery do not replace this client-rendering check.

## Client-side OCR test cases

Covered automatically by `OcrImageCheck` (integration, real database) plus unit tests for `PreviewableImage`, `CandidateSourceImageResolver`, both image endpoints and `OcrControl`:

- A candidate whose message has no previewable image renders **no** OCR button and does not load `assets/ocr.js` or `assets/ocr.css` at all.
- A candidate with a poster renders exactly one `data-adct-ocr` control pointing at the description field, and the page links both assets.
- The #63 candidate detail screen shows the message's **first** stored browser-readable image beside the edit form through the same `OcrControl`, with an `alt="Event poster"` image and a nonce-bound `AttachmentImageEndpoint` URL. A notice with several photographs is one poster plus several downloads, not several posters. The recognised text still lands in the description box for a reviewer to correct, and is still never posted back.
- The control's `data-target` **resolves to a real form control** on the same page, matched as an `id` or a `name` on an `input`, `textarea` or `select`. This is asserted by resolution, not by string match, because a target that never resolves still renders perfectly valid HTML while the recognised text is silently discarded — a control that cannot write its result anywhere is worse than no control.
- The token page renders exactly one layout selector and one confidence slider, both inside exactly one `<details data-adct-ocr-settings>` folded behind one `<summary>` labelled **Advanced**, with no `<details open>`. The summary's `title` names the Tesseract PSM of the current layout, and every option's `title` restates that option's own label with its PSM. Each marker is counted as a distinct string, because `data-adct-ocr-settings` and `data-adct-ocr-summary` are prefixes of no other attribute in play and a bare `data-adct-ocr` count would silently count all of them.
- The page links both assets and loads `assets/ocr-settings.js` **before** `assets/ocr.js`. Both are deferred, so document order is what guarantees the OCR module can read the layout list; swapped, it would fall back to the default silently.
- `OcrControlTest` cross-checks the PHP `LAYOUTS` list against the `LAYOUTS` in `assets/ocr-settings.js`, so the two renderings of the same choices cannot drift apart unnoticed. It checks the **labels** as well as the PSM numbers, because `ocr-settings.js` writes the readout and the disclosure's tooltip from its own copy: a label that drifted would have the summary naming a layout other than the one on screen. Only the numbers were cross-checked before, which is exactly how the two lists first diverged.
- `tests/Node/ocr-settings.test.mjs` and `tests/Node/ocr-target.test.mjs` run under the built-in Node test runner (`npm run test:assets`, its own CI job, no dependencies). They cover the layout and confidence arithmetic, the line filter including the case where a result carries no block data, and the target resolver against a hand-built fake DOM — both spellings, and the guarantee that a field name is never used as a selector.
- Serving a poster leaks nothing: the rendered page contains the attachment id in its own image URL but no storage name, no submitter email and no other candidate's id.
- The attachment row is byte-identical after OCR is offered — `extracted_text` stays null, `extraction_method` stays `none`, and no row is added or removed.
- `ActionTokenImageEndpoint::allowedImage()` returns the token's own poster, and null for another candidate's poster, a non-existent id, and a HEIC/PDF. Reading the image does **not** consume the token.

**Still to check by hand, in a browser** (ADR 0018): that clicking the button loads tesseract.js from jsDelivr and fills the description field; that typed text is never overwritten; that an empty result or a failed/blocked CDN request leaves the field for manual entry and shows a clear message; and that the reviewer's approval still publishes only what they confirmed. These need a real browser and network access, so no automated check asserts them.

## Pre-launch check on a temporary staging instance

There is no permanent staging site. Before the first launch (and optionally before big releases):
- Create a temporary xneelo instance (e.g. a subdomain with its own database) with the release zip and a separate test mailbox (e.g. `events-test@adct.org.za`). Follow the create-then-remove procedure in [Temporary staging instance: create, then remove](hosting-environment.md#temporary-staging-instance-create-then-remove) — in particular the test mailbox requires the staging name to be ordered as a Multiple (addon) domain first, not created as a bare subdomain.
- Before connecting SMTP or testing confirmations, enable **Parish Intake → Outbound email → Test mode**, add only the test mailbox address/domain to the allow-list, and confirm the conspicuous admin banner appears. Verify a non-allow-listed fixture is shown as suppressed and is not delivered; test mode restricts Parish Intake queue mail only.
- Release checklist: install zip on a fresh separate database → verify the automatically installed schema and record its version for the first non-prerelease release → send test emails (single event, bulletin, poster PDF, recurring event) → confirm via the emailed links → approve as a dean and as a reviewer → make a change as a verified contact and revert it → check the events page and ICS feed → check the health dashboard, that the 2-hourly xneelo cron and the external pinger both trigger jobs within the time budget, and that the mail queue respects the hourly cap. For later releases, also verify a data-preserving upgrade from the supported release baseline.
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

`composer test` also includes `PluginBootWithoutWordPressTest`, which calls `Plugin::boot()` in a subprocess with no WordPress loaded and no stubs for `get_option()`, `add_action()` or the activation hooks. This is the load the release build performs in `scripts/check-release-bootstrap.php`, so it catches an unguarded WordPress call in plugin construction that the unit suite would otherwise miss: every other unit test loads WordPress or a stub, and a fatal during the release build surfaces only in the zip-build step of CI. Guard such calls with `function_exists()` and, in `src/WordPress/Plugin.php`, prefer guarding the single helper both call sites share.

Without a local PHP, use the Docker commands in the [development guide](development.md#local-setup-windows-no-php-install-needed). CI (`.github/workflows/ci.yml`) runs `composer validate`, a `php -l` lint and `composer test` on PHP 8.2, 8.3 and 8.4 for every PR and push to `main`, plus the same steps on PHP 8.5 as an advisory `continue-on-error` job. Because `phpunit.xml.dist` sets `failOnDeprecation`, `failOnWarning` and `failOnNotice` to `true`, the 8.2–8.4 jobs are what enforce the rule that nothing deprecated in PHP 8.3 or later is used.

The integration suite runs against a disposable `wp-env` Docker environment and installs `dist/adct-parish-intake.zip` with WP-CLI. The raw repository checkout is not activated or used as the plugin. With Node.js/npm and Docker Desktop installed, run:

```powershell
npm ci
docker run --rm -v "${PWD}:/app" -w /app composer:2 sh scripts/build-release.sh
npm run test:integration
```

The event-listing check also seeds 150 fictional parishes and 1,800 occurrences. It measures cold/warm listing cost, exercises multi-type (including secondary assigned types), parish/deanery combinations and bookmarked paging, checks that a cancelled occurrence is badged as cancelled on the listing while only a postponed event's `status_flag` earns the postponed badge, and checks that REST and no-JavaScript output reject oversized inputs and hide unpublished events and private contact data.

Issue #71's revert is covered by `RevertChangeHandlerTest` (15 unit tests) rather than an installed-ZIP check, because it is a transactional handler: the tests drive `START TRANSACTION`/`COMMIT`/`ROLLBACK`, `FOR UPDATE` row locks and a fake `$wpdb` that records statement order, so they can assert that a mid-revert failure leaves no partial trail, that the changed state is snapshotted *before* the restore rather than after, and that the recipient's live approver role is re-resolved inside the transaction. The core test is a token minted while someone held authority over two deaneries being refused once they are moved to one, with the deanery they still hold unaffected. Change-notice delivery and `event_changes.notified_at` remain unimplemented, so no installed-ZIP check exercises the revert link end to end yet.

The test runner creates an isolated `wp-env` project and Docker data directory per checkout. Local runs leave the environment running, even on failure; CI stops its environment after the run. Set an unused absolute `WP_ENV_HOME` and distinct `WP_ENV_PORT` and `WP_ENV_TESTS_PORT` values for parallel runs, and never reuse another session's environment. `composer test` remains the unit/smoke suite and does not start WordPress. CI (`.github/workflows/ci.yml`) runs `composer validate`, a `php -l` lint and `composer test` on PHP 8.2, 8.3 and 8.4 for every PR and push to `main`, with PHP 8.5 as an advisory `continue-on-error` job, plus separate WordPress and GreenMail integration jobs on PHP 8.2.

`wp-env` is used instead of the Playground CLI for integration tests because it supplies a normal WordPress/MySQL environment and WP-CLI in Docker. That lets CI install the exact release zip and exercise the admin menu/page without browser automation.

The PHP 8.2 CI matrix job appends the fixture-score table to the GitHub Actions step summary. The score command exits successfully for parser mismatches so it reports quality without hiding failures from the PHPUnit golden tests; it exits unsuccessfully only if a fixture cannot be loaded or parsed.
