# Documentation

Design and knowledge for the ADCT parish intake plugin.

| Document | What it covers |
|---|---|
| [Development guide](development.md) | Local setup with Docker, rules, definition of done, first build order |
| [Backlog and coordination guide](parish-intake-project-backlog.md) | Phases, epics, work items, how to work on issues |
| [Architecture](architecture.md) | Components, data flow, scheduled jobs, trust model, security |
| [Data model](data-model.md) | Tables, event post type, state machines, retention |
| [Hosting environment](hosting-environment.md) | xneelo limits and what follows from them |
| [Parser findings from real samples](parser-samples.md) | What real bulletins and posters showed; fixture and privacy rules |
| [Testing](testing.md) | Test layers, fixtures, PR previews, test sites, pre-launch check |
| [Decisions (ADRs)](decisions/README.md) | Why things are built the way they are |
| [Seed data](../data/seed/README.md) | Deaneries and parishes (124) for the directory import |
| [Reviews](reviews/) | Plan and design reviews, e.g. [2026-09 initial plan review](reviews/2026-09-initial-plan-review.md) |

When behaviour or design changes, update the matching document in the same PR. Record new decisions as an ADR.
