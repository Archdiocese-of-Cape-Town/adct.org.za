# Architecture

The parish intake system is a single WordPress plugin (`adct-parish-intake`) running on the existing adct.org.za site on xneelo shared hosting. It collects parish communications, extracts events from them, checks them with the submitter, and publishes them to a public events page with an ICS feed.

## Goals

- Mostly **non-technical operators**: no extra servers, no daemons, no command line needed day to day.
- **Runs within shared-hosting limits** (see [hosting environment](hosting-environment.md)).
- **Offline first**: deterministic parsing by default; AI and OCR are optional plug-ins that always fall back.
- **Human in the loop at low cost**: the submitter confirms their own events by email, then a dean or an archdiocese reviewer approves them, straight from an email if they like.
- **Testable**: the domain core runs without WordPress.

## High-level flow

```mermaid
flowchart LR
    subgraph Inputs
        E[events@adct.org.za<br/>IMAP]
        G[Google Calendar ICS]
        P[Monthly PDF URL]
        S[Secondary sources<br/>Facebook / forwarded WhatsApp / RSS]
        M[Manual entry / portal]
    end

    subgraph Plugin["WordPress plugin"]
        I[Ingestion jobs<br/>batched, checkpointed]
        R[(Inbound messages<br/>+ attachments)]
        X[Text extraction<br/>PDF / optional OCR]
        PA[Parsing pipeline<br/>normalise → split blocks → rules → directory lookup → recurrence → score → optional AI]
        C[(Event candidates)]
        T{Submitter<br/>confirmation}
        Q[Approval queues<br/>dean + archdiocese reviewers<br/>first to act wins]
        EV[(adct_event posts<br/>+ occurrences)]
        MON[Monitoring & reminders]
    end

    subgraph Outputs
        W[Public events page<br/>near me / type / date]
        F[ICS feed]
        N[Emails: confirmation, approval,<br/>change notices, reminders, alerts]
    end

    E & G & P & S --> I --> R --> X --> PA --> C --> T
    M --> C
    T -- confirmed --> Q
    T -- submitter is an approver --> EV
    Q -- approved --> EV
    C -- change by verified contact --> EV
    EV -- change notice --> N
    T & Q --> N
    EV --> W & F
    I --> MON --> N
```

## Components

### 1. Domain core (`src/Core/…`, no WordPress dependency)
Pure PHP 8.2, covered by unit tests and loaded through Composer PSR-4:
- `Parsing` – the stage pipeline (normalise → split into blocks → rule extraction → directory lookup → date/time → recurrence → classification → confidence → optional AI).
- `Recurrence` – RRULE model and expansion of upcoming occurrences (Africa/Johannesburg).
- `Matching` – duplicate/update detection between candidates and existing events.
- `Trust` – decides the next step for a candidate: send for confirmation, route to the approval queues, publish (self-approval or a verified contact's change to a published event), or ignore.
- `Approval` – parallel dean/reviewer queues, atomic "first to act wins", self-approval, reminders.
- `Directory` – parish/contact import and parser-facing parish and venue lookup over active names, aliases and suburb-qualified names, returning parish/venue IDs and venue coordinates.
- `Sources` – typed source registry and `SourceHealthRecorder`; the WordPress source repository persists registry and health state without putting WordPress dependencies in the core.
- `Ingestion` – `MailboxPollingJob` polls active email sources by UIDVALIDITY and UID, within the job runner's time/item budgets. It stores each raw RFC 822 message and eligible attachments before advancing the mailbox checkpoint, de-duplicates by source plus Message-ID or a versioned content hash, and moves completed mail to Processed. Oversized messages are recorded as skipped and moved to Too large; attachment policy failures remain visible in admin. The polling stage detects declared and likely automated/list mail, stores a conservative `is_auto_reply` no-confirmation flag, and parses SPF/DKIM/DMARC `Authentication-Results` claims into versioned structured data. Authentication claims are untrusted unless the authserv-id matches an explicitly configured receiving-MTA ID; the default allowlist is empty. It does not populate `body_text`, create candidates or send email. The separate `MimeMessageParser` decodes raw RFC 822 mail into `Message` + attachment metadata using the pure-PHP `zbateson/mail-mime-parser` dependency; it decodes multipart/alternative, related and mixed bodies, transfer encodings and charsets, selects useful plain text before HTML, and retains thread/list/automation/authentication headers without interpreting authentication results.
- `Ingestion\Imap` – `ImapMailbox` implements `MailboxInterface` with a bounded pure-PHP IMAP client. It searches by UID, fetches raw RFC 822 bytes and metadata, lists/creates folders, marks messages seen, and moves them using MOVE or COPY/STORE/EXPUNGE. It checks message size before fetching the body (30 MiB by default) and uses PHP stream sockets with TLS peer verification enabled by default; unencrypted or unverified connections require an explicit test-only opt-in (ADR 0013).
- Mailbox settings use a validated Core value object and a connection-test service. The service logs in, counts unseen messages in the inbox, checks the processed folder, closes the connection, and records only safe success/failure health details; it does not fetch message bodies or poll.
- The poller stores raw `.eml` files and accepted attachments in an unguessable-name `private` uploads subdirectory with deny rules and an `index.php` guard. Attachments are stored only when both their declared MIME type and file signature are allowed; the provisional allowlist is PDF, JPEG, PNG, WebP, HEIC and HEIF, with a 15 MiB per-file cap. Skipped attachment metadata and oversized-message errors are shown on the Mailboxes screen.
- `Support\EmailTextCleaner` – reusable plain-text cleanup that separates quoted replies and signatures, extracts original forward headers, and removes common newsletter footers. `Support\HtmlToTextConverter` preserves paragraphs, lists and table rows without requiring `ext-dom`.
- `Tokens` – random, SHA-256-hashed, single-use action tokens and renewal-link rate limits.
- `Jobs` – due checks, bounded item processing, checkpoints, lock/state ports and run results.

The core talks to the outside through interfaces (ports): `MailboxInterface`, `ClockInterface`, `EventRepositoryInterface`, `DirectorySnapshotProviderInterface`, `AiProviderInterface`, `OcrProviderInterface`, `MailerInterface`, `MailDeliveryInterface`, `MailQueueRepositoryInterface`, `RecipientPolicyInterface`, the action-token store/rate-limit/key/renewal/handler ports, and `HttpClientInterface`. The parsing pipeline, its stages and value objects live under `Core\Parsing`; shared pure-PHP helpers live under `Core\Support`; contracts live under `Core\Ports`. `MailerInterface` accepts one validated recipient and queues a fully composed HTML/text message with a priority and optional idempotency key. The Core queue service also exposes pending-count/oldest-age statistics. Action-token renewal is queued through this port at login/confirmation priority; action-specific confirmation, login and approval handlers remain separate behind the typed handler registry. The WordPress wiring reads operator-managed Test mode settings dynamically and applies the recipient policy both when mail is queued and immediately before a queued row is claimed for delivery. Test mode is off by default, supports exact addresses and exact domains, and persists blocked rows as `suppressed`; empty or invalid settings fail closed. This policy applies only to the Parish Intake queue, not to unrelated WordPress mail.

`Message` keeps the received sender/date and envelope subject, plus separate quoted text, signature text, original-forward metadata, and raw values for Message-ID, In-Reply-To, References, Auto-Submitted, List-Id and Authentication-Results. Attachment records identify the corresponding MIME part and Content-ID; attachment bytes remain with the stored raw message for a later attachment-extraction stage. The MIME parser dependency is namespace-prefixed into release packages with Strauss (ADR 0012).

The IMAP protocol uses `Ingestion\Imap\TransportInterface`; `StreamTransport` lives in `src/Core` because it uses only PHP's standard stream API and no WordPress functions. The adapter never logs LOGIN commands or credentials. A message over the configured fetch limit raises `MessageTooLarge` after a size-only FETCH, before its body is requested.

`Pipeline::parseAll(Message)` returns a `ParseOutcome` containing ordered event candidates, shared notes/errors and block metadata. Before shared-context extraction or event-candidate classification, the deterministic `SectionSkipper` filters recognizable non-event sections: Mass times and intentions, sick lists, deceased, anniversaries, raffle winners, collections/finances, banking details and readings. Its case- and punctuation-insensitive phrase lists have Core defaults and can be edited from Parish Intake → Settings. Weekly Mass-times tables are also recognized from weekday/time rows that identify Mass or Service. A standalone category phrase or a category match formatted as a Markdown/underlined, all-caps, colon-terminated or otherwise recognized heading starts a section skip through the next heading, even when that section contains dates and times. A category phrase at the start of running text is skipped only when its block has no explicit date plus time or event noun; a block with that event signal is retained as a candidate, receives the text-free `section_keyword_overridden: <category>` note and has its confidence reduced by 0.1. For skipped sections, the outcome retains only the zero-based `block_index`, `classification: skipped` and category `reason`, plus a count-by-category note. Skipped text is not used in candidates, shared context, descriptions, notes, block metadata or AI input.

The `BulletinBlockSplitter` then uses headings, blank-line groups, list items, date-led lines and pipe-delimited table rows; it passes safe parish, month/year and venue context to each candidate without using AI. Each candidate has a zero-based `block_index` and a trimmed `source_snippet` capped at 2,000 characters. To bound work and review output, the provisional `ParseOutcome::MAX_CANDIDATES` limit is 50: an over-limit message returns the first 50 in document order, reports `candidate_limit_exceeded:<total>`, and lowers retained candidates' confidence to zero with reprocessing flagged for manual review. Other obvious non-event blocks are reported as notices/skipped blocks rather than event candidates.

The `DirectoryLookupStage` runs after deterministic extraction and before confidence scoring. Its `DirectoryLookup` uses the pure-PHP `DirectorySnapshotProviderInterface`; the WordPress adapter supplies active venue records, parish names/area/church/suburb data and sender trust links from a versioned transient snapshot. A single verified parish link supplies the parish and its default venue. A verified sender linked to several parishes is disambiguated only by a unique text/context match; pending and unknown senders use text/context only, and blocked senders are left unchanged for intake to ignore elsewhere. Parish matching reuses `VenueNameNormalizer` for St/Saint, punctuation and apostrophe variants and also checks suburb-qualified names. An unqualified church-name-only body-text match without verified-sender support has 0.6 confidence; fuller or suburb-qualified matches and explicit block context retain their existing confidence. Matched IDs, venue coordinates, source and confidence are added as new `fields` and text-free notes; a labelled venue is retained unless it matches a directory venue. Parish, venue or contact writes advance the directory version so the next lookup lazily rebuilds the cached snapshot.

The existing `Pipeline::parse(Message): ParseResult` API remains compatible and returns the first candidate (or a notice result when none were found); optional AI enrichment remains a later, separate pipeline stage.

`RecurrenceDetectionStage` converts recognized deterministic phrases into a supported RFC 5545 RRULE, keeps the source phrase as human-readable text, and validates every emitted rule with `RRuleValidator` (using `RRulePresetMapper` for the matching presets). When a recurring candidate has no explicit `event_date`, its anchor is the first matching occurrence on or after the date parser's reference date: a bulletin date range when present, otherwise the received date or injected clock. This adds the `recurrence_anchor_inferred` note and reduces confidence by 0.05. A yearless recurrence end date is resolved to its next calendar occurrence on or after the anchor and noted. Seasonal wording such as "daily during Lent/Advent" remains ambiguous: no liturgical date, anchor or RRULE is guessed; the candidate is flagged for confirmation with `recurrence_ambiguous_season` and the ambiguity confidence penalty.

The WordPress-free `OccurrenceExpander` expands the validated DAILY, WEEKLY, MONTHLY and YEARLY subset in `Africa/Johannesburg` local time. The inclusive rolling window runs from the current site-local date through the same date one year later, clamping a leap-day anniversary to February 28. RRULE UNTIL is inclusive; DTSTART is retained as the first instance and counts once even if it does not match the filters; COUNT applies to RRULE instances before exclusions, RDATEs do not consume COUNT and may fall after UNTIL, and EXDATE removes a matching RRULE or RDATE start. Positive BYDAY ordinals count from the start of the month/year, while negative ordinals count from the end (for example, `-1SU` is the last Sunday and `-1FR` the last Friday). Missing fifth weekdays do not spill into another month, and a February 29 yearly rule has no generated instance in non-leap years. These semantics are provisional and reversible. The daily `expand_occurrences` job checkpoints its event cursor and fixed window, so a budget-limited batch resumes without shifting the window.

The Manual parser displays the full `ParseOutcome` and uses the same cached directory lookup as the parser pipeline, so admins can enter a sender email to test verified-sender resolution. Until the event-candidate repository is implemented, its legacy prototype table continues to store one row per input message using the first candidate, or the notice result if there are no candidates.

### 2. WordPress adapters (`src/WordPress/…`)
- Plugin bootstrap and hook wiring, the manual parser/admin UI, database schema/repository access, static reports, and the WordPress HTTP client.
- `WordPress\Ai\OpenRouterProvider` implements the core AI port and receives `HttpClientInterface`; only `WordPress\Http\WordPressHttpClient` calls `wp_remote_post`.
- Repositories using `$wpdb` (custom tables in the site's existing WordPress MySQL database) and the `adct_event` post type.
- `WordPressDirectorySnapshotLoader` reads parish, venue and contact repositories; `WordPressDirectorySnapshotCache` stores the snapshot in a transient keyed by the non-autoloaded directory version option. Parish, venue and contact repositories increment that version after successful writes.
- Admin screens (dashboard, review/approval queue, parishes, deaneries and approvers, sources, mailboxes, settings, outbound email, health). Mailboxes are linked to archdiocese-wide email sources; the Mailboxes screen supports settings, an explicit test-connection action and skipped-message/attachment notices. The Outbound email screen manages queue-only Test mode and shows escaped previews of recent suppressed rows to settings managers. Parish sources are editable from each parish's Sources tab; the Sources submenu also lists all sources and manages archdiocese-wide ones.
- Front-end approver queue for deans (magic-link login, no wp-admin).
- Public views: the server-rendered `[adct_events]` shortcode and `adct/events` block read bounded pages from the indexed occurrences table, join only published posts, and render day-grouped cards with local dates. Preset week/month and validated custom ranges share ordinary GET links, so JavaScript is not required. A short-lived transient caches the bounded rows; an event write, status change, term assignment or occurrence replacement rotates the cache version, and cached rows are rechecked against the published post status before display. Single event templates, ICS endpoints and additional filters remain separate work.
- Scheduled jobs via WP-Cron hooks, triggered by site traffic, a 2-hourly xneelo cron backstop, an optional external pinger and a "Check now" button ([ADR 0010](decisions/0010-scheduled-jobs-with-2-hour-cron-limit.md)).
- Queued mailer (`adct_pi_mail_queue` → the site's `wp_mail`/FluentSMTP route) with per-recipient messages, an hourly cap, priorities, retries and sanitized queue statistics ([ADR 0011](decisions/0011-outbound-email-queue-with-hourly-cap.md)), `wp_remote_*` HTTP client, roles and capabilities.

The Composer PSR-4 mappings keep `ADCT\ParishIntake\Core\…` and `ADCT\ParishIntake\WordPress\…` separate. The release zip includes a small source autoloader in `WordPress\Autoloader` alongside the namespace-prefixed Composer dependency loader; development and tests use Composer's generated autoloader.

### 3. Infrastructure adapters
- `ImapMailbox` – the Core's PHP-stream IMAP client (no `ext-imap` or additional Composer package); retrieval and folder operations are behind `MailboxInterface`. GreenMail integration tests verify protocol operations, polling resume and de-duplication, oversized-message handling, and the connection-test service's success, wrong-password and missing-processed-folder results.
- `IcsSource` – fetches and parses ICS feeds (Google Calendar).
- `PdfTextExtractor` – pure-PHP text extraction (e.g. `smalot/pdfparser`) that uses text positions to rebuild columns, because most bulletins have 2–3 columns ([parser findings](parser-samples.md)).
- Optional `OcrProvider`s (OCR.space free tier, OpenAI-compatible vision models) – off by default.
- Optional `OpenAiCompatibleProvider` for AI enrichment (OpenRouter `:free` models, Groq, local Ollama during development).

## Scheduled jobs

The framework in `src/Core/Jobs` is WordPress-free. A job decides whether it is due and processes one item per step. `JobRunner` acquires a lease, loads the last checkpoint, processes items until the **60-second time budget** or **100-item budget** is reached, saves each returned checkpoint, and releases its token in `finally`. The budgets are configurable through the runner constructor or per-run arguments. The default lock lease is 180 seconds, longer than the time budget. Jobs receive time through `ClockInterface`.

Each job's state records `last_run_at` (run start), `last_success_at` (updated only after a non-failing batch), the last error message and timestamp, the last run's item count, and its checkpoint. A budget-limited batch is a successful run: its checkpoint is saved and the next due run resumes there. A failed batch records the error without moving `last_success_at`.

`WordPressJobStateStore` stores state in non-autoloaded WordPress options; no database migration or custom table is needed. `WordPressJobLock` uses `add_option` for atomic creation. Expired or malformed locks are removed with a prepared compare-and-delete against the exact stored value, and release also checks the holder's random token so an old holder cannot remove a replacement lock. The WordPress scheduler registers one custom ten-minute WP-Cron event per job; each job's due check prevents unnecessary work between its own intervals. Scheduling and cleanup failures are logged without aborting visitor requests or plugin deactivation. An unexpected cron callback failure is logged and recorded in job state when the state store is available.

| Job | Default interval | Work |
|---|---|---|
| `framework_heartbeat` | due every 10 min | Currently registered framework check only; no parish data or email work. |
| `poll_mailboxes` | due every 10 min | Check active mailboxes whose source interval has elapsed, resume incomplete UID scans, and retry failures with backoff; store raw mail and eligible attachments, de-duplicate and file completed messages. Parsing is a later job. |
| `process_queue` | due every 10 min | Planned: extract text, parse, create candidates, queue confirmation and approver emails. |
| `send_mail` | every trigger; scheduled every 10 min | Send queued email in priority order within the job's 60-second / 100-item budget and the rolling hourly cap. A priority-1 enqueue can request one immediate item through the same job lock and cap. |
| `poll_sources` | hourly | Planned: poll a few active ICS/PDF/secondary sources per run (oldest `last_checked_at` first) and record checks, successes, failures and item times through `SourceHealthRecorder`. No source polling adapter is registered yet. |
| `expand_occurrences` | daily | Rebuild published events' occurrences for the inclusive 12-month window; per-event replacement is transactional, and batches resume from a checkpoint. |
| `monitoring` | daily | Planned: update source health, create reminder candidates, send inactivity reminders, approval reminders and approver digests (each can be switched off). |
| `retention` | daily | Planned: delete raw messages/attachments past retention, prune tokens and logs. |

The framework heartbeat, `poll_mailboxes`, `send_mail` and `expand_occurrences` are registered jobs. The heartbeat only exercises scheduling; the mailbox poller stores incoming mail but does not parse messages or process events. The sender calls the only plugin `wp_mail()` adapter and does not add SMTP credentials; delivery continues through the site's FluentSMTP setup. Priority 1 is login/confirmation, priority 2 approver/change notices, and priority 3 reminders/digests. The default cap is 100 successfully sent recipients in the preceding 60 minutes, configurable from `wp-config.php` through `ADCT_PI_MAIL_HOURLY_CAP` (1–500). Database-atomic claims reserve capacity for in-flight deliveries. A thrown delivery exception or worker interruption with unknown outcome leaves the row in `sending` and holds its reservation for the full 60-minute window before retrying with backoff; a mail accepted by SMTP just before the worker died can therefore be duplicated on that retry. Messages with the same recipient and `group_key` are de-duplicated only after composition: identical payloads return the existing row, and changed content under the same key is an error rather than silently discarded. The key is per recipient and does not coalesce content. The `expand_occurrences` job reads only published `adct_event` posts. Saves from the manual editor and REST API rebuild one event immediately; moving an event away from Published or deleting it removes its rows. Draft, pending and private events therefore have no occurrence rows. Each event's stale rows are deleted and its replacement rows inserted inside one transaction, so a failed insert preserves the previous set. Occurrence rows copy the assigned event type (the lowest term ID when several are assigned), coordinates from the venue with parish fallback, and the cancellation flag. An archdiocese-wide event stores a NULL parish and NULL coordinates. Cancelled events keep their dates with `is_cancelled = 1`; postponed events keep their dates with `is_cancelled = 0` and remain distinguishable by the event post's status flag. A separate source-polling adapter/job is not registered.

xneelo cron jobs can run at most every 2 hours, and there is no WP-CLI, so jobs have several triggers ([ADR 0010](decisions/0010-scheduled-jobs-with-2-hour-cron-limit.md)):
- WP-Cron stays on, so site visits run due jobs.
- One 2-hourly xneelo cron job calls `https://<site>/wp-cron.php?doing_wp_cron` over HTTP as a backstop.
- An optional free external pinger (cron-job.org) calls it every 5–10 minutes for timely intake.
- **Parish Intake → Scheduled jobs** lists each registered job and its last run, last success, last error, and last run's item count. The per-job **Run now** action is a capability- and nonce-protected POST that uses the same lock and budgets while bypassing only the due check. Running `poll_mailboxes` manually polls active mailboxes; later queue-processing jobs will provide the parsing and event-work checks.

The intervals above are "due" times: a job runs on the first trigger after it is due, including after a trigger gap of up to 2 hours. The scheduled-jobs page reports run state; the separate health-dashboard warning after 2 h 15 min remains a later dashboard feature.

## Trust, confirmation and approval (summary)

See [ADR 0004](decisions/0004-trust-and-confirmation-model.md) (confirmation, safe links) and [ADR 0008](decisions/0008-approval-by-dean-or-archdiocese-reviewer.md) (approval).

- **Every new event** goes through two steps:
  1. The **submitter confirms** the emailed preview.
  2. It then appears **at the same time** in the queue of the parish's **deanery approvers** (the dean) and of the **archdiocese reviewers**. The first to Approve or Reject decides.
- Approvers get an email with the preview and Approve / Reject / Edit links, or a daily digest. Reminders go out after N days (can be switched off).
- **Self-approval:** if the submitter is an approver for that parish, their confirmation publishes directly.
- **Known sender** (a verified address linked to a parish): parish and venue are filled in automatically. Their **changes and cancellations to published events publish immediately**, and approvers get a change notice with Revert / Unpublish.
- **Unknown sender:** same two steps. Approvers see an "unknown sender" warning and can link the address to a parish, which makes it known.
- **Secondary/monitored source:** a "we found this on X, do you want it published?" email goes to the parish's known contacts. Their "yes" counts as the confirmation, and approval follows.
- **No dean needed:** parishes without a deanery, or in a deanery with no active approver set up yet, go to archdiocese reviewers only. The dashboard shows which deaneries have no approver.
- **Routine repeats are silent:** an unchanged event that appears again (e.g. in every weekly bulletin) is matched to the existing event and not sent for confirmation or approval again ([parser findings](parser-samples.md)).
- Mailbox screening treats `Auto-Submitted`, autoreply headers and delivery-status reports as declared automation; list/precedence headers, null `Return-Path` and no-reply-style addresses are likely automation. Both classifications set the persisted no-confirmation flag, including for mailing lists. The Core `InboundMailPolicy::canSendConfirmation()` also rejects unsafe sender or reply-to addresses; mailbox polling does not send confirmations.
- SPF/DKIM/DMARC verdicts are stored as structured `auth_results`, never as rendered raw headers. An unknown or untrusted authserv-id is not a verified pass and cannot grant sender trust. A reported DMARC failure on a verified contact is shown as a review flag, and `InboundMailPolicy::canApplyInstantChange()` returns false for that message; #71 will connect this policy to the approval route.
- To change an event, reply with the changes, or click *Edit* (magic link) and edit it in the portal.

## Parish self-service

Parish contacts are WordPress users with a custom `parish_contact` role, linked to one or more parishes. They log in with a **magic link** sent to their address. The auth cookie lifetime is extended (e.g. 1 year, configurable). They can list, edit, cancel and duplicate events for their parishes only. See [ADR 0007](decisions/0007-auth-with-wordpress-users-and-magic-links.md).

## Public output

- `adct_event` custom post type with a REST-enabled `adct_event_type` taxonomy. Its seeded Social, Spiritual, Formation, Liturgy/Mass, Youth, Outreach, Fundraising, Meeting and Other terms are **provisional and admin-editable**.
- An **occurrence table** holds each dated instance (once-off and expanded recurring), so date-range queries and "near me" sorting are simple SQL. Rows exist only for published posts, store UTC instants plus the SAST local date, and are replaced transactionally; a missing parish is stored as SQL NULL rather than a synthetic parish ID.
- **Near me**: each parish/venue has latitude/longitude (entered once, optional free geocoding lookup). The browser's location (with permission) or a typed suburb is used to sort by distance.
- An **ICS feed** covers everything, with filtered variants (per parish, per type), so people can subscribe in Google/Apple/Outlook calendars.
- A "featured" flag separates big once-off events from routine recurring ones on the listing.

## Security notes

- Admin screens use dedicated capabilities for settings, directory management, review, reports and deanery approval. The pure role/capability map lives in `Core\Auth\Capabilities`; a versioned, additive installer creates custom roles and adds missing capabilities without replacing other role capabilities.
- `parish_contact` and `deanery_approver` are read-only roles. They cannot use `wp-admin` or the admin bar, except for AJAX and `admin-post.php` requests; users who also have editorial `edit_posts` access are not blocked.
- Deanery approvals require an active assignment in `adct_pi_deanery_approvers` matching the parish's `deanery_id`. `adct_pi_review` holders can approve across deaneries, including parishes without a deanery.
- Admin actions use capabilities + nonces. Portal actions check parish ownership.
- Authentication-Results headers are sender-controlled unless the receiving MTA removes external copies and stamps its own result. The trusted authserv-id allowlist is empty by default; configure only an exact ID after confirming the MTA's header-stripping/rewriting behavior. A matching header alone is not proof of sender identity.
- Mailbox settings always verify the TLS peer and hostname in production. A TLS failure advises operators to use the server hostname listed on its certificate; unencrypted connections and disabled verification remain explicit test-only options (ADR 0013).
- Action links use 32 CSPRNG bytes encoded as unpadded base64url; only the SHA-256 hash, purpose, subject, normalized email, expiry and used-at state are stored. Defaults are 14 days for event actions and 30 minutes for login. The front-end endpoint renders a handler-provided preview on GET without mutation; only a nonce-protected POST can atomically consume a token and invoke a registered handler. The form POST omits the token from its URL; responses are no-store, no-referrer and noindex. Unexpired/unknown purposes have no built-in business handler in this issue.
- New-link requests are POST-only and use atomic fixed-UTC-hour HMAC buckets: at most 3 requests per email and 20 per source IP. The limiter stores no raw email/IP and prunes inactive buckets after 48 hours. Renewal delivery uses `MailerInterface` and priority 1; it never calls `wp_mail` directly.
- Email HTML is never rendered unsanitised; attachments are stored outside the web root or with deny rules, and only allowed MIME types are processed.
- AI prompts treat email content as untrusted data; AI output is schema-validated and never decides publishing.
- Secrets are registered by purpose (`ADCT_PI_AI_API_KEY`, `ADCT_PI_IMAP_PASSWORD`, `ADCT_PI_OCR_API_KEY`). A non-empty string constant in `wp-config.php` takes precedence over its stored option; the Core resolver receives constant and option readers as injected callbacks, and the WordPress adapter supplies those readers. Empty or non-string constants fall back to the option. Mailbox passwords use a stable mailbox-ID scope: `ADCT_PI_IMAP_PASSWORD_MAILBOX_<ID>` overrides the default `ADCT_PI_IMAP_PASSWORD`, which overrides that mailbox's non-autoloaded option. Register future global secrets as `ADCT_PI_<PURPOSE>` constants paired with `adct_parish_intake_<purpose>` options; secret reads must go through the resolver. Secret values are not included in settings output, parse results, or debug output.
- Uninstall removes the plugin's custom roles and its custom capabilities from built-in roles. Tables and plugin data are retained until the owner makes a separate data-deletion decision.

## Related documents

- [Data model](data-model.md)
- [Hosting environment](hosting-environment.md)
- [Testing](testing.md)
- [Decisions (ADRs)](decisions/)
- [Backlog](parish-intake-project-backlog.md)
