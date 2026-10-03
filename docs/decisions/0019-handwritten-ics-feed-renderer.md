# ADR 0019: Handwritten ICS feed renderer, validated by a test-only library

- Status: Proposed
- Date: 2026-10-02

## Context
ADR 0017 prefers a maintained library over handwritten implementations of standard protocols and serializers, and requires any exception to be documented. The public ICS feed (`?adct_ics=1`, plus `parish`/`type` variants) is produced by `Core\Events\IcsCalendar`, a handwritten RFC 5545 renderer shipped in #117.

The feed is not a general-purpose iCalendar library's job, because its inputs are not RFC 5545 inputs. Each `VEVENT` is built from our own occurrence rows and recurrence data: an event may have many occurrence rows, `RDATE`/`EXDATE` come from our expansion window, `UNTIL` is emitted in UTC, and `DTSTART`/`DTEND` follow the occurrence model (all-day ends exclusive, timed events keep their duration). A library serializer would still need a per-event `VEVENT` built in the project's exact shape; the escaping and folding layer would then sit in front of it anyway.

A library is also available and useful here, but only on the test side. `sabre/vobject` (MIT, actively maintained, pure PHP, PHP 8.1+) can parse a complete `VCALENDAR`, so it is added as a **dev-only** dependency and used by the unit tests to re-parse the renderer's output and assert it is well-formed — an independent check that the handwritten writer does not emit a stream a real client would reject. It is not required at runtime, and the release zip is built with `--no-dev` (`scripts/build-release.sh`), so nothing changes for the host.

## Decision
- Keep `Core\Events\IcsCalendar` as a handwritten renderer for the `?adct_ics=1` feed. It is a documented exception to ADR 0017 because its per-event assembly logic is specific to our occurrence model, not to iCalendar.
- Keep `sabre/vobject` in `require-dev` only. Do not add an iCalendar library to `require`.
- The exception is conditional: if the feed later needs interoperability features (alarms, attendees, attachments, recurrence override exceptions, calendar-level `METHOD`), or a library is judged to fit the occurrence model, revisit this record then.

## Consequences
- `composer.json` gains `sabre/vobject: ^5.0` under `require-dev`. `composer.lock` updates; the runtime dependency set is unchanged.
- RFC 5545 conformance is a property of the tests, not of the writer alone: the unit tests assert CRLF endings, 75-octet folding, escaping round-trips, control-character stripping, invalid-UTF-8 handling, `TZID`/`VALUE=DATE` parameter forms and stable `UID`s, and a sabre/vobject re-parse catches structural mistakes the assertions miss.
- `docs/testing.md` records that the feed was additionally validated outside the plugin with Python `icalendar`.
- Reviewers who expect a library serializer should read this record first.