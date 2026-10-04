# ADR 0022: Surface unparsed date/time in the notes column, with no schema change

- Status: Proposed
- Date: 2026-10-05
- Issue: [#167](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/167)

## Context

[#165](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/165) fixed the *parsing* half of a failure mode: compact times like `830am` are now read. [#167](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/167) is the other half, and it outlives #165 — fixing the parser only moves the set of phrases that fall through.

The behaviour #167 was filed against, measured on `main` at `d8971cc`:

- A date that reads as a date but names no day on the calendar — `32 October`, `29 February 2026`, `12/13/2026` — produced **no field and no note at all**. The notice arrived looking successfully parsed, with the date simply absent.
- A time that reads as a clock but resolves to nothing — `12:60am`, `9:99am` — produced free text in the candidate's notes, covering only meridiem-bearing forms, and that text was read by no screen.

Nothing distinguished "the notice contained no date" from "a date was written and we could not read it". A submitter received a confirmation email that looked complete, and an approver saw a candidate with an empty date field and no signal that anything had been lost.

## Decision

1. **`notes` carries the signal. No schema change, no migration.** `adct_pi_event_candidates.notes` is already a `longtext` holding machine-readable `reason:payload` diagnostics (`skipped_sections: …`, `possible_missed_event_after_skipped_section: …`), and it is already selected by `ReviewQueueRepository::find()`, so it is on the row every queue screen has. Adding a column would have required a versioned migration under [ADR 0016](0016-pre-release-schema-changes.md) and a new `adct_pi_db_version` literal, for state that is genuinely ephemeral. `ParseResult::fieldConfidence()` is the precedent for surfacing something new without touching the schema.
2. **Two reasons, not prose.** `Core\Parsing\UnparsedDateTimeCandidate` owns `unparsed_date_candidate` and `unparsed_time_candidate`, each followed by `:` and the phrase that was found, whitespace-collapsed and truncated to 40 characters. A screen branches on the token; a human reads the phrase. `describe()` is the single place the wording lives, so the sentence cannot drift between surfaces. The truncation is what keeps a raw line of a notice out of an email or a page: only a short span a reviewer needs in order to recognise it travels, never the whole notice.
3. **Surfaced, not merely recorded.** The sentence appears in words on the candidate detail screen (as a "Parser warning" row carrying the phrase, beside an acknowledgement control inside the approval form), on the review queue row, on the dean's front-end editor and on the dean's queue *list*, in the submitter's confirmation email in both plain text and HTML, and in the emailed approval preview and the approver notice email. A row or log line naming a reason is deliberately not sufficient: the submitter is the only person who can fix the source text, so they have to be able to see which phrase went unread.
4. **A date blocks approval until it is acknowledged. A time does not.** `ReviewQueuePolicy::canBulkApprove()` refuses an unresolved date unless the reviewer passes an explicit acknowledgement. The acknowledgement is **per attempt**, not per candidate: `ReviewQueueRepository::decide()` re-reads the stored row inside its transaction, so a note stored against an earlier attempt cannot make a just-corrected save look approved. A date is mandatory anyway (`CandidateEditValidator`), so "approve with no date at all" is unreachable; what the checkbox asserts is that the reviewer has read the date they are approving. An unresolved time does not block, because an all-day event is legitimate and `CandidatePublisher` already sets `all_day` from the absence of `event_time`. Nothing blocks indefinitely — every candidate can be approved.
5. **Copy says "could not be read", never "empty".** A missing field and an unread field are different states with different fixes, and the wording carries that distinction on every surface.

## Consequences

- The phrase reaches a reviewer, but the notice it came from does not. A reviewer who needs more context goes back to the original email, which is still the authoritative source and is not made public by this decision.
- Acknowledgement is not remembered per candidate. A reviewer who approves a corrected candidate and is asked to acknowledge again is being asked the same question twice; that is the cost of refusing to let a stale note stand in for a fresh decision.
- `handleBulk()` passes no acknowledgement, so a bulk approval refuses an unresolved date with no inline route out of it. That is deliberate — bulk approval is not a place to consent on a candidate's behalf — but it is the one path where a reviewer is told no without being offered the yes.
- Two notes now mean two different things in the golden corpus. `notes` is the outcome's list and `candidate_notes` is the primary candidate's, because merging them let the outcome's empty list win the merge and a fixture could not assert a note about a candidate at all.

## Considered and rejected

- **A new `has_unparsed_date` column.** Queryable and unambiguous, but it is per-attempt state stored per-candidate, so it would need a migration and a `db_version` bump for something a note already carries.
- **Treating an unread value as an ordinary field error.** `ParseResult::$errors` is for a notice that cannot be extracted at all; a partly-read notice still produces a usable candidate, and demoting it would push every such notice into manual review instead of in front of a human with the phrase in view.
- **Silently coercing an unread time to `00:00`.** That is what `10.00-12:00am` used to do, and it is worse than an empty field: it publishes a wrong start time.