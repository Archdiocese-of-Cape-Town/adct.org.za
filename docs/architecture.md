# Architecture

The parish intake system is a single WordPress plugin (`adct-parish-intake`) running on the existing adct.org.za site on xneelo shared hosting. It collects parish communications, extracts events from them, checks them with the submitter, and publishes them to a public events page with an ICS feed.

## Goals

- Mostly **non-technical operators**: no extra servers, no daemons, no command line needed day to day.
- **Runs within shared-hosting limits** (see [hosting environment](hosting-environment.md)).
- **Offline first**: deterministic parsing by default; AI and OCR are optional plug-ins that always fall back.
- **Human in the loop at low cost**: the submitter confirms their own events by email; admins only handle exceptions.
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
        T{Trust &<br/>confirmation}
        Q[Admin review queue]
        EV[(adct_event posts<br/>+ occurrences)]
        MON[Monitoring & reminders]
    end

    subgraph Outputs
        W[Public events page<br/>near me / type / date]
        F[ICS feed]
        N[Emails: confirmation,<br/>reminders, alerts]
    end

    E & G & P & S --> I --> R --> X --> PA --> C --> T
    M --> C
    T -- known sender confirms --> EV
    T -- unknown / low confidence / secondary --> Q --> EV
    T --> N
    EV --> W & F
    I --> MON --> N
```

## Components

### 1. Domain core (`src/Core/…`, no WordPress dependency)
Pure PHP 8.2, covered by unit tests:
- `Parsing` – the stage pipeline (normalise → split into blocks → rule extraction → gazetteer lookup → date/time → recurrence → classification → confidence → optional AI).
- `Recurrence` – RRULE model and expansion of upcoming occurrences (Africa/Johannesburg).
- `Matching` – duplicate/update detection between candidates and existing events.
- `Trust` – decides the next step for a candidate (auto-publish after confirmation, admin review, ignore).
- `Mail` – MIME parsing into `Message` + `Attachment`, auto-reply/bounce detection.
- `Tokens` – signed, single-use action tokens.

The core talks to the outside through interfaces (ports): `MailboxInterface`, `ClockInterface`, `EventRepositoryInterface`, `AiProviderInterface`, `OcrProviderInterface`, `MailerInterface`.

### 2. WordPress adapters (`src/WordPress/…`)
- Repositories using `$wpdb` (custom tables) and the `adct_event` post type.
- Admin screens (dashboard, review queue, parishes, sources, settings, health).
- Public views: shortcode/block for the events page, single event template, ICS endpoint, REST endpoints for filtering.
- Scheduled jobs via WP-Cron hooks, triggered by a real xneelo cron job.
- `wp_mail` mailer, `wp_remote_*` HTTP client, roles and capabilities.

### 3. Infrastructure adapters
- `ImapMailbox` – pure-PHP IMAP over TLS (ext-imap is not available). Fetches unseen messages, stores raw RFC 822 source, marks/moves processed mail, deletes it after retention.
- `IcsSource` – fetches and parses ICS feeds (Google Calendar).
- `PdfTextExtractor` – pure-PHP text extraction (e.g. `smalot/pdfparser`).
- Optional `OcrProvider`s (OCR.space free tier, OpenAI-compatible vision models) – off by default.
- Optional `OpenAiCompatibleProvider` for AI enrichment (OpenRouter `:free` models, Groq, local Ollama during development).

## Scheduled jobs

All jobs follow the same pattern: take a lock (transient/option with expiry), work in a loop until a **time budget (~60 s)** or item budget is used, save a checkpoint after each item, release the lock. If a run dies, the lock expires and the next run resumes from the checkpoint.

| Job | Default interval | Work |
|---|---|---|
| `poll_mailboxes` | every 5–10 min | Fetch new mail, store raw message + attachments, queue for parsing. |
| `process_queue` | every 5 min | Extract text, parse, create candidates, send confirmation emails. |
| `poll_sources` | hourly | ICS/PDF/secondary sources, a few sources per run (oldest `last_checked_at` first). |
| `expand_occurrences` | daily | Refresh the occurrence table for the next 12 months. |
| `monitoring` | daily | Update source health, create reminder candidates, send reminders (if enabled). |
| `retention` | daily | Delete raw messages/attachments past retention, prune tokens and logs. |

A real cron job on xneelo calls `wp-cron.php` (or `wp cron event run --due-now`) every 5 minutes. `DISABLE_WP_CRON` is set so visitor traffic doesn't trigger it.

## Trust and confirmation (summary)

See [ADR 0004](decisions/0004-trust-and-confirmation-model.md).

- **Known sender** (a verified address linked to a parish) → confirmation email with preview → *Approve* publishes immediately.
- **Unknown sender** → confirmation email still sent (proves mailbox ownership) → admin review queue. The admin can link the address to a parish; the address then becomes known.
- **Secondary/monitored source** → "we found this on X, do you want it published?" email to the parish's known contacts → approval publishes.
- Changes: reply with changes, or click *Edit* (magic link) to edit in the portal.

## Parish self-service

Parish contacts are WordPress users with a custom `parish_contact` role, linked to one or more parishes. They log in with a **magic link** sent to their address. The auth cookie lifetime is extended (e.g. 1 year, configurable). They can list, edit, cancel and duplicate events for their parishes only. See [ADR 0007](decisions/0007-auth-with-wordpress-users-and-magic-links.md).

## Public output

- `adct_event` custom post type with an `adct_event_type` taxonomy (Liturgy/Mass, Spiritual, Formation, Social, Youth, Outreach, Fundraiser, Pilgrimage, Other – admin-editable).
- An **occurrence table** holds each dated instance (once-off and expanded recurring), so date-range queries and "near me" sorting are simple SQL.
- **Near me**: each parish/venue has latitude/longitude (entered once, optional free geocoding lookup). The browser's location (with permission) or a typed suburb is used to sort by distance.
- An **ICS feed** covers everything, with filtered variants (per parish, per type), so people can subscribe in Google/Apple/Outlook calendars.
- A "featured" flag separates big once-off events from routine recurring ones on the listing.

## Security notes

- All admin actions use capabilities + nonces. Portal actions check parish ownership.
- Action tokens: random 32-byte values, stored hashed, single-use, with expiry; GET shows a confirmation page, POST performs the action.
- Email HTML is never rendered unsanitised; attachments are stored outside the web root or with deny rules, and only allowed MIME types are processed.
- AI prompts treat email content as untrusted data; AI output is schema-validated and never decides publishing.
- Secrets (IMAP password, API keys) are preferably defined as constants in `wp-config.php` rather than stored in the database; the settings UI says so.

## Related documents

- [Data model](data-model.md)
- [Hosting environment](hosting-environment.md)
- [Testing](testing.md)
- [Decisions (ADRs)](decisions/)
- [Backlog](parish-intake-project-backlog.md)
