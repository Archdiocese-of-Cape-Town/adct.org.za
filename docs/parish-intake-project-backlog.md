# Parish intake project coordination guide

This repository contains the prototype for the parish intake system. Use GitHub Issues as the source of truth for work, and use the GitHub Project to track state across multiple sessions.

## Project setup

Create a GitHub Project for this repository and add these fields:

| Field | Type | Suggested values |
| --- | --- | --- |
| Status | Single select | Backlog, Ready, In Progress, Blocked, Review, Done |
| Priority | Single select | P0, P1, P2, P3 |
| Phase | Single select | Phase 1, Phase 2, Phase 3 |
| Area | Single select | Data model, Ingestion, Parsing, Admin UI, Publishing, Operations |
| Depends on | Text | Issue numbers or short dependency note |
| Outcome type | Single select | Epic, Feature, Enhancement, Research, Operational task |

## Issue workflow

- Create top-level workstreams with the `Epic` issue template.
- Create session-sized implementation tasks with the `Work item` issue template.
- Link every work item to its parent epic.
- Keep acceptance criteria user-visible and specific.
- Leave handoff notes in the issue before ending a session.

## Phase 1 scope

Phase 1 should stay focused on email-first intake in a WordPress-compatible/shared-hosting environment.

Use the current plugin as the starting point for:

- manual parser workflow
- offline-first parsing
- recurrence detection
- optional AI fallback
- stored parse results

Defer these to later tracked issues:

- automated inbox polling beyond the first import path
- WhatsApp or public social ingestion
- advanced publishing integrations beyond the prototype export

## Epic backlog

Create these epic issues first:

1. Epic: Parish directory and source registry
2. Epic: Email intake and message ingestion
3. Epic: Offline-first parsing pipeline
4. Epic: Review/admin workflow
5. Epic: Publishing and export
6. Epic: Monitoring, reminders, and follow-up
7. Epic: AI fallback integration
8. Epic: Secondary channels beyond email

## Initial work-item backlog

### Epic: Parish directory and source registry

- Create parish records with contact and source metadata
- Track source types per parish
- Record expected update frequency per parish
- Track active, paused, and unreliable sources
- Store source identifiers and last-seen checkpoints

### Epic: Email intake and message ingestion

- Add inbox configuration storage
- Implement mailbox polling/import
- Track raw messages and attachments
- Prevent duplicate imports
- Record processing status and ingest errors
- Add last-checked and last-success state per inbox/source

### Epic: Offline-first parsing pipeline

- Harden source normalization
- Improve rule-based extraction for title, venue, contact, date, and category
- Expand recurrence detection for monthly/weekly phrasing
- Add confidence scoring review thresholds
- Support attachment/poster text extraction
- Preserve reprocessing capability as parsing rules improve

### Epic: Review/admin workflow

- Add parish intake dashboard
- Add queue views for new, low-confidence, failed, and approved items
- Add item detail page for source, extracted fields, recurrence, and notes
- Add manual correction and approval workflow
- Add audit trail for admin decisions
- Add clearer settings/help text for operators

### Epic: Publishing and export

- Define event/news output model
- Export approved items to static HTML view
- Add calendar-oriented output for dated events
- Define featured vs routine content behavior
- Add publish/unpublish state handling
- Add WordPress publishing path for approved items if needed later

### Epic: Monitoring, reminders, and follow-up

- Flag parishes with overdue updates
- Record expected cadence by parish
- Track last received communication
- Generate reminder candidates
- Record follow-up attempts and outcomes
- Add operational reporting for inactive sources

### Epic: AI fallback integration

- Keep AI optional and disabled by default
- Support provider configuration cleanly
- Send only low-confidence cases to AI
- Store AI usage and provenance
- Keep deterministic parsing as the primary path
- Add re-review rules for AI-assisted parses

### Epic: Secondary channels beyond email

- Define source model for WhatsApp/manual/public social sources
- Add per-source checkpoints and polling state
- Run secondary sources in small batches
- Mark fragile channels separately from official channels
- Keep manual entry as fallback for unsupported channels

## Recommended first board population

### Ready

- Create parish/source data model
- Add inbox configuration storage
- Implement mailbox polling/import
- Add duplicate detection for incoming messages
- Add review queue states
- Improve recurrence extraction coverage
- Add attachment/poster text extraction path
- Add overdue parish/source monitoring

### Backlog

- All epic issues
- All later-phase issues not listed in Ready

### Blocked

- WhatsApp/public social ingestion until the source strategy is chosen

### Done

- Manual parser screen
- AI settings screen
- Offline-first base parser
- Recurrence prototype
- Static report generation

## Dependency rules

- Inbox polling depends on inbox/source configuration.
- Review queue depends on imported message storage.
- Publishing depends on approved extracted items.
- Reminder workflow depends on parish/source cadence tracking.
- Secondary channels depend on the same source registry and checkpoint model.
- AI fallback improvements depend on baseline deterministic parsing quality.

## Session handoff standard

Every issue should include:

- Goal
- Scope
- Out of scope
- Dependencies
- Acceptance criteria
- Notes for next session
- Related epic
- Suggested project field values

Each work item should:

- end in a user-visible outcome
- be small enough for a later session to resume quickly
- state whether it changes data model, ingestion, parsing, admin UI, publishing, or operations

## Working model for contributors

1. Pick one `Ready` issue.
2. Move it to `In Progress`.
3. Implement only that scoped issue.
4. Leave handoff notes before ending the session.
5. Move the issue to `Review` or `Done`.
6. Use epic issues for overview only; do not rely on old chat history once issues exist.
