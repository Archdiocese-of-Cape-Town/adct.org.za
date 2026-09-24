# Review of the initial parish intake plan (September 2026)

This review compares the first generated backlog (`docs/parish-intake-project-backlog.md` as of PR #2) and the prototype plugin with the original project idea. It records what was missing, what was risky, and which decisions were taken as a result. The backlog was rewritten afterwards.

## The original idea (summary)

- Gather news and events from roughly 100–150 parishes and groups, whatever channel they use (email, WhatsApp, Facebook, Google Calendar, PDFs).
- Publish them on the archdiocesan WordPress site as an events calendar/listing that can be filtered by **location (near me)**, **type** (social, spiritual, formation, …) and **date**.
- Start with email submissions to `events@adct.org.za`.
- Reply to the submitter with a **preview** and **approve / deny** links. Let the owning submitter make changes.
- Let parishes **log in with just their email** (magic link) and stay logged in for a long time.
- A parish can use several addresses (priest, secretary, office). Sender addresses should be **learned** over time.
- Posters/PDFs are converted to text, offline where possible, with optional free AI endpoints and a fallback.
- **Inactivity reminders** that can be turned on and off.
- Several admins.
- One **official** input source per parish. Other sources are **monitored**, with a prompt like "we found this on X, do you want it published?".
- Recurring events (e.g. a healing Mass every first Friday) must be told apart from once-off bigger events.
- The archdiocese Google Calendar and its monthly PDF at `adct.org.za/calendar` are input sources.
- Secondary channels are processed in small batches so they stay within runtime limits.
- It must be easy to run and maintain for mostly non-technical people, on xneelo shared hosting.

## What the first plan got right

- A WordPress plugin on the existing shared host is the right platform (see [ADR 0001](../decisions/0001-wordpress-plugin-on-shared-hosting.md)).
- Offline-first parsing, with AI optional and off by default.
- Raw input is stored separately from extracted data, so items can be re-parsed later.
- Source checkpoints ("last seen", "last success") are considered.
- GitHub Issues are used as the source of truth across sessions.

## Gaps: parts of the original idea that were missing

| # | Missing item | Where it is now covered |
|---|---|---|
| 1 | Confirmation email to the submitter with a preview and approve/deny links. This is the core feedback loop. | Epic: Submitter confirmation loop |
| 2 | Parish self-service: magic-link login, long sessions, edit or cancel own events. | Epic: Parish self-service portal |
| 3 | Several addresses per parish, sender learning, queue for unknown senders. | Epic: Parish directory and sender registry |
| 4 | One official source per parish vs. monitored sources ("found on X, publish?"). | Epic: Parish directory; Epic: Secondary channels |
| 5 | Google Calendar (ICS) input and the monthly PDF. | Epic: Calendar and document inputs |
| 6 | Public events page with near-me, type and date filters. | Epic: Public events page and feeds |
| 7 | Recurring vs once-off events (RRULE storage, exceptions, cancellations, "featured" flag). | Epic: Events model and publishing |
| 8 | Several admin roles with specific permissions. | Epic: Foundations; ADR 0007 |
| 9 | On/off switches for inactivity reminders (global and per parish). | Epic: Monitoring and reminders |

## Design risks found

1. **One email can contain many events.** Parish bulletins list the week's events. The prototype schema has one row per message with a single `title`. The data model must separate *message → event candidates → published events* ([data model](../data-model.md)).
2. **Updates, duplicates and cancellations.** The same event is often re-sent with changes, sent from two addresses, or later found on Facebook. Candidates need matching rules and a "supersedes" link.
3. **Email link scanners.** Microsoft Safe Links, Gmail and antivirus gateways open links in emails automatically. If approve/deny acted on a plain GET request, events could be approved without anyone clicking. Links must open a confirmation page, and only a button press (POST) performs the action. Tokens must be signed, single-use and time-limited ([ADR 0004](../decisions/0004-trust-and-confirmation-model.md)).
4. **Reply loops and spoofing.** Auto-replies, bounces and mailing-list traffic must not get confirmation emails back (check `Auto-Submitted`, `Precedence`, `Return-Path: <>`, `noreply`). The From address is easy to fake, so SPF/DKIM results in `Authentication-Results` should be recorded as a trust signal. Only the confirmation step proves the sender controls the mailbox.
5. **Shared-hosting limits.** WP-Cron only runs when the site has visitors, so a real cron job should call it. PHP runs are capped at 90 s. `ext-imap` is not installed and has been removed from PHP 8.4 core. Composer libraries can clash with other plugins unless their namespaces are prefixed. See [hosting environment](../hosting-environment.md).
6. **Posters and images.** Shared hosting cannot run Tesseract OCR. PDFs with a text layer can be read in pure PHP. Image-only posters need an optional external OCR service, or manual entry, which is always available.
7. **What "offline NLP" can realistically do.** PHP has no mature NLP library for this. Realistic tools are rule-based grammars, keyword lists and lookup lists (parish names, venues, event types). The parish directory is the strongest signal: a known sender already tells us the parish, its venue and its location. The confirmation loop means parser mistakes are cheap to fix, so the parser has to be *good enough*, not perfect ([ADR 0005](../decisions/0005-offline-first-parsing-with-pluggable-ai.md)).
8. **AI settings.** The default model `openrouter/auto` routes to paid models. Free use needs an explicit `:free` model, which has rate limits. AI output must be validated against a strict schema. Email text is untrusted and can contain prompt injection. AI must never decide whether something gets published.
9. **Privacy (POPIA).** Raw emails contain personal details. Set a retention period for raw messages and attachments, and put archdiocese contact details in outgoing emails. An opt-out feature is out of scope.
10. **Timezone and date formats.** All dates are Africa/Johannesburg, and numeric dates are day-first (DD/MM). The prototype has a bug: `new DateTimeImmutable('12/10/2026')` is read as 10 December (US order). It also doesn't handle dates without a year ("Sunday 5 October") or relative dates ("this Sunday").
11. **Testing.** There is only one smoke test with loose assertions and no CI. We need PHPUnit, a set of anonymised real emails with expected outputs, CI on the host's PHP version, and WordPress/IMAP integration tests ([testing](../testing.md)).
12. **Deployment and operations.** No release process or health view existed. Non-technical admins need a single zip to upload, a health dashboard ("last checked", errors) and email alerts when polling fails.
13. **Secondary channels.** Adding a bot to WhatsApp groups goes against WhatsApp's terms of service and needs an always-on process, which shared hosting can't provide. Reading Facebook public page feeds requires Meta app review. Both need research spikes and realistic expectations ([ADR 0006](../decisions/0006-secondary-channels-approach.md)).

## Issues with the documents themselves

- The backlog was organised by technical layer (data model, ingestion, parsing…), which gives no usable result until every layer is done. It was reorganised into **vertical slices**: an end-to-end email → confirm → publish loop comes first.
- Phases 2 and 3 were in the project field list but were never defined.
- The "Done" list overstated prototype work (e.g. "Recurrence prototype" covered only a few phrasings).
- The README pointed to CI paths (`/home/runner/...`) instead of repository paths.

## Decisions taken with the project owner

- Stay with a WordPress plugin on xneelo shared hosting.
- Use an xneelo IMAP mailbox for intake. Access goes through an adapter, so another provider (such as Outlook.com, which needs OAuth2) can be added later.
- A known parish sender who confirms publishes immediately. Unknown senders need an admin to approve. *(Revised; see below.)*
- Parsing is English only.
- Publish to a new WordPress events page with filters and an ICS feed. The existing Google Calendar becomes an input.
- No opt-out feature; outgoing emails include contact details.

## Revisions after review (2026-09-24)

- **Approval:** every new event now needs approval. The submitter confirms, then the parish's dean or any archdiocese reviewer approves.
  - Both queues get the item at once, and the first to act wins.
  - Self-approval is allowed.
  - A verified contact's changes to published events go live immediately, with a change notice and one-click revert.
  - See [ADR 0008](../decisions/0008-approval-by-dean-or-archdiocese-reviewer.md). New issues: #68, #69, #70, #71, #72.
- **Database:** the plugin uses the site's existing WordPress MySQL database (MariaDB 10.11 on xneelo, which is MySQL-compatible) and only portable SQL.
- **Testing and staging:**
  - No permanent staging site.
  - Every PR gets a WordPress Playground preview button built from the CI zip.
  - InstaWP/TasteWP use the same zip for real mail tests.
  - A temporary xneelo staging instance is used once, as the final check before launch.
  - See [ADR 0009](../decisions/0009-preview-and-test-environments.md).
- **Hosting answers:**
  - Cron at most every 2 hours (10 jobs max).
  - No WP-CLI.
  - 500 emails per hour.
  - 30 MB messages.
  - Outbound HTTPS allowed.

  This led to [ADR 0010](../decisions/0010-scheduled-jobs-with-2-hour-cron-limit.md) (WP-Cron + 2-hourly backstop + optional pinger + "Check now") and [ADR 0011](../decisions/0011-outbound-email-queue-with-hourly-cap.md) (mail queue with an hourly cap). New issue: #76.
- **Real samples** (13 bulletins and posters) led to these changes ([parser findings](../parser-samples.md)):
  - skip personal/non-event sections: new issue #74;
  - silent matching of weekly repeats: #46 raised to P0;
  - venues and outstations: #33 raised to P0;
  - position-aware PDF text;
  - optional OCR brought forward: new issue #75.
- **Deaneries and parishes** are seeded from the official directory ([seed data](../../data/seed/README.md)). A deanery without a dean set up still works: reviewers approve.
