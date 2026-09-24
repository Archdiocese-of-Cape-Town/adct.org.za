# Data model

All custom tables use the WordPress table prefix (shown as `wp_` here) and the `adct_pi_` namespace. Every table has `created_at` / `updated_at` (UTC). Local-time values are stored with the timezone `Africa/Johannesburg`. Schema changes go through versioned migrations (a `adct_pi_db_version` option plus `dbDelta`).

The prototype table `wp_adct_parish_intake_items` is replaced. Its rows can be migrated into `inbound_messages` + `event_candidates`, or dropped if they are only test data.

## Entity overview

```mermaid
erDiagram
    PARISH ||--o{ PARISH_CONTACT : has
    PARISH ||--o{ SOURCE : has
    PARISH ||--o{ VENUE : has
    SOURCE ||--o{ INBOUND_MESSAGE : produces
    INBOUND_MESSAGE ||--o{ ATTACHMENT : has
    INBOUND_MESSAGE ||--o{ EVENT_CANDIDATE : "parsed into (1..n)"
    EVENT_CANDIDATE }o--o| EVENT : "creates / updates"
    EVENT ||--o{ OCCURRENCE : expands
    EVENT_CANDIDATE ||--o{ ACTION_TOKEN : "approve / deny / edit"
    PARISH ||--o{ FOLLOW_UP : reminders
```

## Tables

### `adct_pi_parishes`
| Column | Notes |
|---|---|
| id | PK |
| name, slug | e.g. "St Mary's, Woodstock" |
| kind | `parish`, `mission`, `group`, `archdiocese`, `school`, `other` |
| deanery | optional |
| address, suburb | |
| latitude, longitude | decimal(9,6); used for "near me" |
| website, phone | |
| official_source_id | FK → sources; the channel whose content is trusted |
| expected_cadence_days | e.g. 30; null = no expectation |
| reminders_enabled | per-parish on/off (a global switch also exists) |
| status | `active`, `inactive` |
| notes | admin notes |

### `adct_pi_venues`
Named places for a parish (church, hall, outstation), each with its own address and lat/lng. Used by the parser to look up venue names.

### `adct_pi_parish_contacts` (sender registry)
| Column | Notes |
|---|---|
| id | PK |
| parish_id | FK (a contact can be linked to several parishes via repeated rows) |
| email | lower-cased, unique per parish |
| display_name, role_label | e.g. "Fr John", "Secretary", "Office" |
| trust | `unknown`, `pending`, `verified`, `blocked` |
| verified_at | set when the contact confirms via token or an admin links them |
| wp_user_id | set when a portal account exists |
| last_seen_at | last message received from this address |
| receives_reminders | bool |

Learning rule: when an unknown address submits, a `pending` row is created with a best-guess parish (from the parser/gazetteer). An admin confirms the link and it becomes `verified`.

### `adct_pi_sources`
| Column | Notes |
|---|---|
| id | PK |
| parish_id | nullable (archdiocese-wide sources) |
| type | `email`, `ics`, `pdf_url`, `facebook_page`, `whatsapp_forward`, `rss`, `web_page`, `manual` |
| identifier | mailbox address, URL, page id… |
| role | `official` or `monitored` |
| status | `active`, `paused`, `unreliable`, `disabled` |
| poll_interval_minutes | |
| checkpoint | JSON (e.g. IMAP UIDVALIDITY + last UID, ICS ETag, last post id) |
| last_checked_at, last_success_at, last_item_at | health tracking |
| consecutive_failures, last_error | |

### `adct_pi_inbound_messages`
| Column | Notes |
|---|---|
| id | PK |
| source_id | FK |
| external_id | e.g. `Message-ID` header; unique with source_id → prevents duplicates |
| content_hash | sha256 of normalised body; catches re-sends with a new Message-ID |
| sender_email, sender_name, subject | |
| received_at | |
| raw_path | path to stored raw `.eml` (not in DB, to save space) |
| body_text | extracted plain text |
| auth_results | JSON: SPF/DKIM/DMARC verdicts |
| is_auto_reply | bool; auto-replies never get confirmations |
| status | see state machine |
| error | |
| retention_until | raw data deleted after this date |

### `adct_pi_attachments`
message_id, filename, mime_type, size_bytes, storage_path, content_hash, `extracted_text`, `extraction_method` (`pdf_text`, `ocr_external`, `ai_vision`, `manual`, `none`), status.

### `adct_pi_event_candidates`
| Column | Notes |
|---|---|
| id | PK |
| message_id | FK (nullable for manual/portal entries) |
| block_index | position in the message (a bulletin can yield several candidates) |
| parish_id | best guess or known |
| fields | JSON: title, description, start, end, all_day, venue_id / venue_text, contact, event_type, featured, image attachment id |
| recurrence | JSON: RRULE parts + human text + `ambiguous` flag |
| confidence | 0–1 |
| parser_version, strategies, notes | provenance |
| ai_used, ai_provider, ai_model | provenance |
| match_event_id, match_kind | `new`, `update`, `duplicate`, `cancellation` |
| status | see state machine |
| decided_by, decided_at, decision_note | audit |

### `adct_event` (WordPress custom post type)
Post title/content hold the public text. Post meta holds: `parish_id`, `venue_id`, `start_local`, `end_local`, `all_day`, `rrule`, `exdates` (JSON), `rdates`, `featured`, `status_flag` (`scheduled`, `cancelled`, `postponed`), `source_candidate_id`, `contact`. Taxonomy: `adct_event_type`.

A post type (rather than only custom tables) gives WordPress revisions, search, REST, theme templates and editor familiarity for admins.

### `adct_pi_occurrences`
event_id, start_utc, end_utc, start_local_date, parish_id, event_type_term_id, latitude, longitude, is_cancelled. It is rebuilt for an event whenever the event is saved, and refreshed daily for a rolling 12-month window. All public listing queries read from this table.

### `adct_pi_action_tokens`
token_hash, purpose (`approve`, `deny`, `edit`, `login`, `publish_found`), subject_type/subject_id, email, expires_at, used_at, created_ip.

### `adct_pi_follow_ups`
parish_id, kind (`inactivity_reminder`, `found_on_secondary`, `unknown_sender`), channel, sent_at, outcome, note.

### `adct_pi_audit_log`
actor (user id / email / `system`), action, subject_type, subject_id, details JSON, created_at.

## State machines

### Inbound message
```mermaid
stateDiagram-v2
    [*] --> received
    received --> ignored: auto-reply / bounce / blocked sender
    received --> extracting
    extracting --> parsed
    extracting --> failed
    failed --> extracting: retry / reprocess
    parsed --> [*]
```

### Event candidate
```mermaid
stateDiagram-v2
    [*] --> draft
    draft --> awaiting_submitter: confirmation email sent
    draft --> awaiting_admin: unknown sender with no reply-to, or confirmations disabled
    awaiting_submitter --> published: approved by known sender
    awaiting_submitter --> awaiting_admin: approved by unknown sender
    awaiting_submitter --> rejected: denied
    awaiting_submitter --> expired: no response in N days
    expired --> awaiting_admin: if configured
    awaiting_admin --> published: admin approves
    awaiting_admin --> rejected: admin rejects
    draft --> duplicate: matches existing event, no changes
    published --> superseded: a newer candidate updated the event
```

### Published event
`scheduled` → `cancelled` / `postponed` (still visible, clearly marked) → the event is trashed only by an admin. Past events stay visible in an archive view.

## Retention (POPIA)

- Raw `.eml` files and attachments: default **12 months** after receipt (configurable), then deleted. Extracted candidates and published events stay.
- Action tokens: deleted 30 days after expiry.
- Audit log: 24 months.
- Outgoing emails show the archdiocese's contact details for questions about personal information.
