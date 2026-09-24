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
        PA[Parsing pipeline<br/>rules → gazetteer → recurrence → score → optional AI]
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
- `Parsing` – the stage pipeline (normalise → split into blocks → rule extraction → gazetteer lookup → date/time → recurrence → classification → confidence → optional AI).
- `Recurrence` – RRULE model and expansion of upcoming occurrences (Africa/Johannesburg).
- `Matching` – duplicate/update detection between candidates and existing events.
- `Trust` – decides the next step for a candidate: send for confirmation, route to the approval queues, publish (self-approval or a verified contact's change to a published event), or ignore.
- `Approval` – parallel dean/reviewer queues, atomic "first to act wins", self-approval, reminders.
- `Mail` – MIME parsing into `Message` + `Attachment`, auto-reply/bounce detection.
- `Tokens` – signed, single-use action tokens.
- `Jobs` – due checks, bounded item processing, checkpoints, lock/state ports and run results.

The core talks to the outside through interfaces (ports): `MailboxInterface`, `ClockInterface`, `EventRepositoryInterface`, `AiProviderInterface`, `OcrProviderInterface`, `MailerInterface` and `HttpClientInterface`. The parsing pipeline, its stages and value objects live under `Core\Parsing`; shared pure-PHP helpers live under `Core\Support`; contracts live under `Core\Ports`. The mailbox, mailer, candidate repository and OCR method signatures are provisional until their first consumers (E2.1, ADR 0011's mail queue, E5.3 and E12 respectively).

### 2. WordPress adapters (`src/WordPress/…`)
- Plugin bootstrap and hook wiring, the manual parser/admin UI, database schema/repository access, static reports, and the WordPress HTTP client.
- `WordPress\Ai\OpenRouterProvider` implements the core AI port and receives `HttpClientInterface`; only `WordPress\Http\WordPressHttpClient` calls `wp_remote_post`.
- Repositories using `$wpdb` (custom tables in the site's existing WordPress MySQL database) and the `adct_event` post type.
- Admin screens (dashboard, review/approval queue, parishes, deaneries and approvers, sources, settings, health).
- Front-end approver queue for deans (magic-link login, no wp-admin).
- Public views: shortcode/block for the events page, single event template, ICS endpoint, REST endpoints for filtering.
- Scheduled jobs via WP-Cron hooks, triggered by site traffic, a 2-hourly xneelo cron backstop, an optional external pinger and a "Check now" button ([ADR 0010](decisions/0010-scheduled-jobs-with-2-hour-cron-limit.md)).
- Queued mailer (`adct_pi_mail_queue` → `wp_mail`) with an hourly cap and priorities ([ADR 0011](decisions/0011-outbound-email-queue-with-hourly-cap.md)), `wp_remote_*` HTTP client, roles and capabilities.

The Composer PSR-4 mappings keep `ADCT\ParishIntake\Core\…` and `ADCT\ParishIntake\WordPress\…` separate. The release zip includes a small source autoloader in `WordPress\Autoloader` alongside the namespace-prefixed Composer dependency loader; development and tests use Composer's generated autoloader.

### 3. Infrastructure adapters
- `ImapMailbox` – pure-PHP IMAP over TLS (ext-imap is not available). Fetches unseen messages, stores raw RFC 822 source, marks/moves processed mail, deletes it after retention.
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
| `poll_mailboxes` | due every 10 min | Planned: fetch new mail, store raw message + attachments, queue for parsing. |
| `process_queue` | due every 10 min | Planned: extract text, parse, create candidates, queue confirmation and approver emails. |
| `send_mail` | every trigger | Planned: send queued email up to the hourly cap, highest priority first. |
| `poll_sources` | hourly | Planned: ICS/PDF/secondary sources, a few sources per run (oldest `last_checked_at` first). |
| `expand_occurrences` | daily | Planned: refresh the occurrence table for the next 12 months. |
| `monitoring` | daily | Planned: update source health, create reminder candidates, send inactivity reminders, approval reminders and approver digests (each can be switched off). |
| `retention` | daily | Planned: delete raw messages/attachments past retention, prune tokens and logs. |

The framework heartbeat is the only job registered until intake work is implemented. It exists to exercise scheduling and the admin screen; it does not poll mail, process events, or send email.

xneelo cron jobs can run at most every 2 hours, and there is no WP-CLI, so jobs have several triggers ([ADR 0010](decisions/0010-scheduled-jobs-with-2-hour-cron-limit.md)):
- WP-Cron stays on, so site visits run due jobs.
- One 2-hourly xneelo cron job calls `https://<site>/wp-cron.php?doing_wp_cron` over HTTP as a backstop.
- An optional free external pinger (cron-job.org) calls it every 5–10 minutes for timely intake.
- **Parish Intake → Scheduled jobs** lists each registered job and its last run, last success, last error, and last run's item count. The per-job **Run now** action is a capability- and nonce-protected POST that uses the same lock and budgets while bypassing only the due check. Once mail and queue jobs are registered, their Run now actions provide the corresponding manual checks; the current heartbeat does no intake work.

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
- To change an event, reply with the changes, or click *Edit* (magic link) and edit it in the portal.

## Parish self-service

Parish contacts are WordPress users with a custom `parish_contact` role, linked to one or more parishes. They log in with a **magic link** sent to their address. The auth cookie lifetime is extended (e.g. 1 year, configurable). They can list, edit, cancel and duplicate events for their parishes only. See [ADR 0007](decisions/0007-auth-with-wordpress-users-and-magic-links.md).

## Public output

- `adct_event` custom post type with an `adct_event_type` taxonomy (Liturgy/Mass, Spiritual, Formation, Social, Youth, Outreach, Fundraiser, Pilgrimage, Other – admin-editable).
- An **occurrence table** holds each dated instance (once-off and expanded recurring), so date-range queries and "near me" sorting are simple SQL.
- **Near me**: each parish/venue has latitude/longitude (entered once, optional free geocoding lookup). The browser's location (with permission) or a typed suburb is used to sort by distance.
- An **ICS feed** covers everything, with filtered variants (per parish, per type), so people can subscribe in Google/Apple/Outlook calendars.
- A "featured" flag separates big once-off events from routine recurring ones on the listing.

## Security notes

- Admin screens use dedicated capabilities for settings, directory management, review, reports and deanery approval. The pure role/capability map lives in `Core\Auth\Capabilities`; a versioned, additive installer creates custom roles and adds missing capabilities without replacing other role capabilities.
- `parish_contact` and `deanery_approver` are read-only roles. They cannot use `wp-admin` or the admin bar, except for AJAX and `admin-post.php` requests; users who also have editorial `edit_posts` access are not blocked.
- Deanery approvals require an active assignment in `adct_pi_deanery_approvers` matching the parish's `deanery_id`. `adct_pi_review` holders can approve across deaneries, including parishes without a deanery.
- Admin actions use capabilities + nonces. Portal actions check parish ownership.
- Action tokens: random 32-byte values, stored hashed, single-use, with expiry; GET shows a confirmation page, POST performs the action.
- Email HTML is never rendered unsanitised; attachments are stored outside the web root or with deny rules, and only allowed MIME types are processed.
- AI prompts treat email content as untrusted data; AI output is schema-validated and never decides publishing.
- Secrets (IMAP password, API keys) are preferably defined as constants in `wp-config.php` rather than stored in the database; the settings UI says so.
- Uninstall removes the plugin's custom roles and its custom capabilities from built-in roles. Tables and plugin data are retained until the owner makes a separate data-deletion decision.

## Related documents

- [Data model](data-model.md)
- [Hosting environment](hosting-environment.md)
- [Testing](testing.md)
- [Decisions (ADRs)](decisions/)
- [Backlog](parish-intake-project-backlog.md)
