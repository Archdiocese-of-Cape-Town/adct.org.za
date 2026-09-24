# ADR 0003: Publish to our own event post type with an ICS feed; Google Calendar becomes an input

- Status: Accepted
- Date: 2026-09-24

## Context
`adct.org.za/calendar` currently embeds the archdiocese Google Calendar, with a link to a monthly PDF below it. The new listing needs filters by location (near me), event type and date. A Google Calendar embed can't do any of these. Options:
1. Push approved events into Google Calendar via its API.
2. Use a third-party events plugin (e.g. The Events Calendar).
3. Our own custom post type with an occurrence table, listing UI and ICS feed.

## Decision
Option 3:
- `adct_event` custom post type, `adct_event_type` taxonomy, and an occurrences table for fast date and distance queries.
- A public events page (shortcode/block) with near-me, type, parish and date filters; single event pages; an ICS feed (all events, plus per parish and per type).
- The existing **archdiocese Google Calendar is imported as an input source** (via its public/secret ICS URL), so archdiocesan events appear in the same listing.
- The Google Calendar embed can stay on `/calendar` until the new page replaces it.

## Consequences
- Full control over filtering, recurrence and "featured" behaviour. No Google API credentials needed.
- We maintain our own recurrence expansion and ICS output (well-understood standards, covered by tests).
- Not locked into a third-party plugin's data model or paid add-ons. If the site later adopts an events plugin, a one-way export can be added.
- People who want events in Google/Apple/Outlook calendars can subscribe to the ICS feed.
