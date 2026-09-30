# Parser findings from real parish samples

In September 2026 we reviewed 13 real attachments (bulletins, newsletters and posters) that parishes sent to the archdiocese. This page records what they showed about the parsing pipeline. **The samples themselves are not in this repository.** They contain personal information, and this repository is public.

- **Private location of the samples:** the archdiocese OneDrive folder *adct.org.za events notices › sample attachments* (ask the web team for access).
- **Fixtures** made from them for tests must be **anonymised** first ([testing](testing.md#parser-fixture-corpus), [#19](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/19)).

## What the samples are

| Kind | Count | Notes |
|---|---|---|
| Weekly parish bulletin or newsletter (PDF from Word or "Print to PDF"), from a few parishes | 8 | Single page, dense, 2–3 columns; all have a text layer. |
| Event poster or announcement with a text layer (PDF) | 2 | One of them is an archdiocesan Mailchimp email printed to PDF (3 pages). |
| Image-only poster (JPG, or a PDF made only of images) | 3 | **About 1 in 4 samples has no text at all**, so OCR or manual entry is needed. |

## Findings and what they change

1. **Most real "submissions" are bulletins, not event notices.** A bulletin mixes a few real events with lots of routine content: Mass times, readings, notices, collections. So:
   - the parser must **split** a bulletin into blocks ([E3.1](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/40));
   - it must **skip non-event sections** ([E3.7](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/74)).
2. **Bulletins contain personal and sensitive information:**
   - Mass intentions (names of the deceased);
   - **sick lists** (health information is *special personal information* under POPIA);
   - anniversaries and raffle winners;
   - bank details;
   - personal cellphone numbers.

   Recognisable sections (Mass times and intentions, sick list, "please pray for", finances and collections, bank details, readings) are dropped **before** candidates are made. Their text is never put into event descriptions or sent to an AI provider. Raw copies follow the retention rules ([data model](data-model.md#retention-popia)).
3. **The same recurring activities appear in every bulletin.** Examples: Bible study every Thursday, a youth ministry, a fellowship group every first Wednesday. A parish sending a weekly bulletin would otherwise trigger about 10 confirmations every week. Across about 75 parishes that would swamp submitters and approvers. So **unchanged repeats of an already-published or pending event are matched silently** and only new or changed items are sent for confirmation. [E3.6](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/46) was raised to **P0** for this reason.
   The matching test corpus includes a weekly Thursday event and a first-Friday monthly rule with different inferred anchors in successive bulletins. Their identical titles, RRULEs and event details link without a second confirmation; changed times, cancellation and postponement notices remain reviewable. Ambiguous matches stay in the Inbox for manual review rather than being discarded.
4. **Multi-column layout.** Plain text extraction mixes the columns together, and Mass-time tables come out one column at a time. Text extraction must use text positions (group text into blocks by x/y position and read each column top to bottom) rather than taking the raw text stream ([E8.1](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/65)). **Done:** `PrinsFrankPdfTextExtractor` bands text into columns on the x-axis and `ColumnAwareTextAssembler` emits each column as its own block, separated by a blank line so the section skipper and block splitter see a boundary. This covers the 2- and 3-column fixtures. Full column detection — interleaved columns, rotated text, tables that read across both columns — is still open and is tracked as follow-up work.
5. **Date and time formats seen:**
   - dates: `Sat 15.6.24`, `Mon 07 Sept`, `Tues 15 Sep`, `9th September`, `Saturday 15th June`, `Sunday 12 April, Sunday 12 July and Sunday 11 October`;
   - times: `8.30 am`, `08:30`, `830am`, `17:00`, `10.00-12:00am` (inconsistent am/pm);
   - times relative to Mass: `after the 9:30am Mass`, `Every Wednesday in the hall after the 9.30am Mass`.

   Years are usually missing. The bulletin's own date range in the file name or header (e.g. "Bulletin 06 September to 13 September 2026") gives the year and the week, and should be used as the reference date instead of the email received date when present ([E0.5](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/21)).

   **Verified against the fixture corpus** (invented stand-ins in `tests/fixtures/emails/`, reference date 2026-10-01):

   | Real format | `event_date` | `event_time` | Fixture |
   |---|---|---|---|
   | `Sat 15.6.24` | `2024-06-15` ✅ | `08:30` from `8.30 am` ✅ | `real-format-dotted-date` |
   | `9th September` | `2027-09-09` ✅ | `830am` → none ❌ [#165](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/165) | `real-format-ordinal-and-bare-time` |
   | `15th September` + `after the 9.30am Mass` | `2027-09-15` ✅ | `09:30` ✅ | `real-format-after-mass-time` |
   | `this coming Saturday` | none ❌ [#132](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/132) | `10:00` ✅ | `relative-date-coming-weekday` |
   | `end of this month` | none ❌ [#132](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/132) | `19:00` ✅ | `relative-date-end-of-month` |
   | `the first of October` | none ❌ [#132](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/132) | `19:00` ✅ | `relative-date-first-of-month` |
   | `10.00-12:00am` | ✅ | `00:00`, no end ❌ [#153](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/153) | `time-range-dotted-am` |
   | `8.30-10.00am` | ✅ | range end only ❌ [#153](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/153) | `time-range-dotted-bare` |
   | `7-9pm` | ✅ | range end only ❌ [#153](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/153) | `time-range-bare-hyphen` |

   The recurring pattern is silence: an unrecognised date or time is dropped with no error and no note,
   so a submission looks successfully parsed while a human reviewer has no signal anything was lost.
   Only the compact `830am` form had no issue at all and was filed as [#165](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/165).

   The rule parser reads numeric dates day-first (`12/10/2026` is 12 October), uses `Africa/Johannesburg` for local dates and times, and resolves a yearless date to its next occurrence on or after the reference date. It uses the message's received timestamp when available, or an injected clock otherwise. It also recognises relative dates such as "this Sunday", "next Friday", "tomorrow" and "tonight", plus ordinal, abbreviated, dotted and ISO dates. An ambiguous "next <weekday>" keeps the first future occurrence and is noted with a small confidence reduction; the submitter can correct the date in the preview. A date or time range keeps its start in `event_date` / `event_time` and its optional end in `event_end_date` / `event_end_time`; single dates and times omit the end fields. Weekday/date mismatches and end-before-start ranges are noted and reduce confidence.
6. **Parishes with several churches.** One bulletin covers a parish with five churches and communities, and events name the church ("at St X", "in the hall"). This needs the venue list per parish, with outstations and mass centres from the [seed data](../data/seed/README.md) ([E1.5](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/33), raised to **P0**).
   - Rule-based extraction stops the venue at periods, commas, semicolons, line breaks, or common event details such as "for a healing Mass", "at 7pm" and "on Saturday". Labelled `Venue:`, `Where:` and `Location:` values accept addresses or short names; unlabelled `at ...` matching remains limited to capitalised names.
   - The directory stores venue names, aliases and locations, with deterministic lookup for `St`/`Saint`, apostrophes, and trailing `Church`/`Hall` variants. The parser now resolves parish and venue matches from the current directory snapshot (#41); E1.3 reuses parish matching only to label a pending sender suggestion, never to create a verified link.
7. **The event may be at another parish.** The archdiocesan email announced a Mass hosted at a different parish from the sender. The venue must be taken from the text when it names another parish, not only from the sender.
8. **Printed emails carry noise.** "View this email in your browser", unsubscribe/preferences footers and social links must be removed. `Core\Support\EmailTextCleaner` now removes these newsletter tails and separates quoted replies and signatures; it is reusable for extracted PDF text too. MIME HTML is converted to text while retaining block, list and table-row structure ([E2.4](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/37)).
9. **Bulletins print the parish's email addresses** (often an own-domain address plus the `@adct.org.za` one). These can be offered as *suggested* contacts for sender learning ([E1.3](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/31)), confirmed by an admin and never added automatically.
10. **OCR matters more than expected.** About a quarter of the samples were images only. Manual entry beside the preview ([E8.2](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/63)) is the fallback that always works. An optional OCR provider was brought forward to Phase 1.5 as a P1 item ([E8.3](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/75)).

## Fixture guidance

[`docs/fixture-anonymisation.md`](fixture-anonymisation.md) is the binding procedure. It supersedes
anything below that looks more permissive — this repository is public and POPIA applies.

- Make a fixture from each sample type:
  - a text-layer bulletin;
  - a multi-church bulletin;
  - a printed Mailchimp email;
  - a text-layer poster;
  - an image-only poster (expected result: "needs manual entry" or OCR).
- **Anonymise before committing** — see the eight-step guide. In short:
  - replace personal names, phone numbers, personal email addresses, bank and card details with fake ones;
  - replace sick lists and Mass intentions with invented names **but keep the headings**, so the
    section-skip tests still work;
  - **parish names and `@adct.org.za` office addresses must also be replaced.** They are identifying in
    a public repository even though the underlying church addresses are themselves public.
- Rebuild `.eml` headers from scratch rather than editing a copy: invented sender, synthetic subject, a
  fixed `Date` with an explicit timezone, and no `Received`, authentication, routing or provider headers.
- For PDFs, commit a small re-created PDF (generated from synthetic text in the same column layout)
  rather than the original file. Regenerate with `php tests/fixtures/pdfs/generate.php`.
- Never paste a real sample into a chat, an issue, a pull request or an automated tool. Review happens
  locally in approved private storage; only the synthetic result is committed.

The corpus layout, the fixture file format, the anonymisation rules and a 12-item review checklist are
documented in [`tests/fixtures/README.md`](../tests/fixtures/README.md).
