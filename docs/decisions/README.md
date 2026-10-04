# Architecture decision records

Short records of important decisions: what was decided, why, and what follows from it. Add a new numbered file for each new decision. Don't rewrite accepted ADRs; supersede them with a new one.

| # | Decision | Status |
|---|---|---|
| [0001](0001-wordpress-plugin-on-shared-hosting.md) | WordPress plugin on the existing shared host | Accepted |
| [0002](0002-email-intake-via-imap-adapter.md) | Email intake via IMAP polling behind a mailbox adapter | Accepted |
| [0003](0003-own-event-post-type-and-ics-output.md) | Own event post type + ICS feed; Google Calendar becomes an input | Accepted |
| [0004](0004-trust-and-confirmation-model.md) | Trust and confirmation model | Accepted; point 4 superseded by 0008 |
| [0005](0005-offline-first-parsing-with-pluggable-ai.md) | Offline-first parsing with pluggable AI and OCR | Accepted |
| [0006](0006-secondary-channels-approach.md) | Approach to secondary channels | Proposed |
| [0007](0007-auth-with-wordpress-users-and-magic-links.md) | Auth with WordPress users, custom roles and magic links | Accepted |
| [0008](0008-approval-by-dean-or-archdiocese-reviewer.md) | Every new event approved by a dean or archdiocese reviewer | Accepted |
| [0009](0009-preview-and-test-environments.md) | Playground PR previews, test sites from the CI zip, temporary staging only | Accepted |
| [0010](0010-scheduled-jobs-with-2-hour-cron-limit.md) | Scheduled jobs with a 2-hour cron limit: WP-Cron + 2-hourly backstop + optional external pinger | Accepted |
| [0011](0011-outbound-email-queue-with-hourly-cap.md) | Outbound email queue with an hourly cap and priorities | Accepted |
| [0012](0012-pure-php-mime-parser.md) | Pure-PHP MIME parsing with a prefixed Composer dependency | Accepted; version line superseded by 0014, HTML tokenizer choice by 0017 |
| [0013](0013-built-in-pure-php-imap-client.md) | Built-in pure-PHP IMAP client | Superseded by 0017 |
| [0014](0014-mail-mime-parser-4-x.md) | MIME parser 4.x to keep wide transitive constraints | Accepted |
| [0015](0015-pure-php-pdf-text-extraction.md) | Pure-PHP PDF text extraction with hard limits | Accepted |
| [0016](0016-pre-release-schema-changes.md) | Pre-release schema changes on disposable installations; migrations after first release | Accepted |
| [0017](0017-library-first-protocol-and-format-handling.md) | Library-first protocol and format handling | Accepted |
| [0018](0018-client-side-ocr-for-image-posters.md) | Opt-in client-side OCR for image posters; server-side OCR still out of scope | Accepted |
| [0019](0019-handwritten-ics-feed-renderer.md) | Handwritten ICS feed renderer, validated by a test-only library (ADR 0017 exception) | Proposed |
| [0020](0020-opt-in-geolocation-near-me-sort.md) | Opt-in geolocation "Near me" sort with a suburb fallback | Accepted |
| [0021](0021-opt-in-server-side-queue-ocr.md) | Opt-in server-side queue OCR for image posters, with unconditional fallback | Proposed |
| [0022](0022-surface-unparsed-date-time-in-the-notes-column.md) | Surface unparsed date/time in the notes column, with no schema change | Proposed |
| [0023](0023-injected-publication-authority-policy.md) | Publication authority is an injected policy, defaulting to review | Proposed |
| [0024](0024-runtime-resolution-of-prefixed-dependency-classes.md) | Resolve prefixed dependency classes by name at runtime; release checks must exercise behaviour | Proposed |

Template:

```markdown
# ADR NNNN: Title

- Status: Proposed | Accepted | Superseded by NNNN
- Date: YYYY-MM-DD

## Context
## Decision
## Consequences
```
