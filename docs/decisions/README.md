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

Template:

```markdown
# ADR NNNN: Title

- Status: Proposed | Accepted | Superseded by NNNN
- Date: YYYY-MM-DD

## Context
## Decision
## Consequences
```
