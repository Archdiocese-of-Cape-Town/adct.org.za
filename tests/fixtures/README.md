# Parser fixtures

Test inputs for the parsing pipeline.

> **No fixture here is a verbatim parish sample.** The 13 real attachments reviewed in September 2026
> stay in approved private storage and are never copied raw into this repository, a chat or an automated
> tool. A fixture may be *derived* from a real one — that is the normal and encouraged case — but it is
> committed only after the content and metadata have been reduced to what the test needs. See the
> provenance table below and
> [`docs/fixture-anonymisation.md`](../../docs/fixture-anonymisation.md), which is the binding guide.
>
> Everything in this directory is currently **synthetic**: it reproduces the *shape* of each real class
> of input with invented text. Nothing derived from a real attachment has been added yet, which is why
> the "at least 10 anonymised real samples" criterion of
> [#19](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/19) is not yet met. The two things
> standing between here and that criterion are access to the private originals and a human who can
> verify the result before it is published — not a rule against committing derived fixtures.

## Layout

| Path | What it holds | Runner |
|---|---|---|
| `emails/<name>.eml` + `emails/<name>.expected.json` | The golden event-parsing corpus | `tests/Unit/Parsing/EmailFixtureCorpusTest.php` |
| `inbound-mail/<name>.eml` + `.expected.json` | Header-signal screening (bounces, no-reply, out-of-office, spoofed auth results) | `tests/Unit/Mail/RawMessageInspectorTest.php` |
| `pdfs/*.pdf` | Re-created PDFs for text extraction and column ordering | `tests/Unit/WordPress/Pdf/PrinsFrankPdfTextExtractorTest.php` |
| `posters/` | A re-created image-only poster PNG, the email carrying it, and a recorded OCR reply | `tests/Unit/Ocr/OcrPosterFixtureReplayTest.php` |
| `directory.json` | Shared invented parish/venue/sender directory | many directory-resolution fixtures |
| `change-notice-directory.json` | Directory used by cancellation/postponement fixtures | `cancellation`, `postponement` |
| `confirmation-email/` | Local HTML/plain-text snapshots of the submitter preview | `ConfirmationEmailRendererTest` |
| `matching/notices.json` | Repeat-matching scenarios | `EventNoticeMatcherTest` |
| `pdfs/generate.php` | Regenerates the committed PDFs | run by hand: `php tests/fixtures/pdfs/generate.php` |
| `posters/generate.php` | Regenerates the committed poster PNG | run by hand: `php tests/fixtures/posters/generate.php` |

`posters/` deliberately has **no** `.expected.json` and is not globbed by `EmailFixtureCorpusTest`, so it
is not part of the golden corpus and moving the fixture score. Its assertions live in
`OcrPosterFixtureReplayTest`, which needs two things a parser expectation cannot express: that a real PNG
attachment becomes a dated candidate once the OCR reply is replayed, and that exactly the recorded bytes
are uploaded. The reply in `posters/recorded-ocr-response.json` is a **recording**, never re-requested at
test time, so the suite makes no network call and does not depend on OCR.space being reachable.

## Adding a fixture

Drop two files in `emails/` with the same basename:

```
emails/my-new-case.eml
emails/my-new-case.expected.json
```

The provider globs both directories and asserts a 1:1 pairing, so nothing else needs registering. Every
`.eml` must carry a `From` header and a `Date` header **with an explicit timezone**; `EmailFixtureLoader`
throws otherwise, which keeps relative dates and fixture metadata deterministic.

```powershell
docker run --rm -v "${PWD}:/app" -w /app composer:2 fixture-score
```

## How expectations work

`*.expected.json` is a **partial** expectation: `FixtureComparator` checks only the keys you list, so
a fixture asserts the fields that matter to the behaviour it protects. List lengths *are* checked, so
`candidates: [...]` also asserts how many candidates the parser found.

| Key | Meaning |
|---|---|
| `classification`, `fields`, `candidate_count` | Top-level parse result (first/primary candidate) |
| `candidates[]` | Per-candidate assertions, each with `block_index` and `fields` |
| `blocks[]` | Block metadata; skips carry only `block_index`, `classification: "skipped"` and a `reason` category |
| `directory_snapshot` | Path to a directory JSON in `tests/fixtures/` |
| `existing_event` | Fictional published event (`id`, `parish_id`, `fields`) that enables `match_kind` / `event_status` |
| `known_failures` | Maps an output path (or its parent) to an open issue, e.g. `"event_date": "#132"` |
| `notes` | The **outcome's** notes: what the pipeline decided as a whole ("Skipped 1 obvious non-event block(s).") |
| `candidate_notes` | The **primary candidate's** notes: what the extractor found (`unparsed_date_candidate:32 October`, confidence scoring, normalisation) |

`notes` and `candidate_notes` are separate because the two lists mean different things and the
outcome's is empty for any clean parse. They used to be collapsed into the single key `notes`, where the
outcome's list won the merge — so a fixture could never assert a note about the candidate at all, and an
unreadable date was invisible to this corpus (#167).

`known_failures` is the reason a known parser bug can be *reported* rather than hidden. A mismatch
covered by it marks the test **incomplete** and links the tracking issue; an untracked mismatch
**fails** the build with a per-field diff:

```
Parser fixture mismatch: my-new-case
  fields.event_date: expected '2026-10-03'; got NULL (untracked)
  fields.event_time: expected '10:00'; got '00:00' (#153)
```

Keep a tracked fixture and update its expectation **when the referenced issue is fixed**. Never delete
a failing fixture, and never weaken an expectation to match current behaviour without its own commit
explaining why. The current set of tracked failures is listed in
[`docs/testing.md`](../../docs/testing.md) §"Tracked fixture failures", which also explains why the overall
score percentage falling is expected while a pre-existing fixture's row falling is a real regression.

Skipped-section text must never appear in expected output, and no `source_snippet` may carry it.

## Provenance: the real class of input each fixture covers

These come from the sample review in [`docs/parser-samples.md`](../../docs/parser-samples.md) §"What
the samples are": 8 bulletins, 2 text-layer posters, 3 image-only posters. Every row below is currently
served by an invented fixture, but a row may equally be served by one **derived** from a real sample in
reduced, anonymised form — record nothing beyond the generic class of input named in the left column.

| Real class of input (from the private review) | Fixture(s) covering it |
|---|---|
| Weekly parish bulletin, 2–3 columns, text layer | `bulletin-multi-event-skip-sections`, `bulletin-numbered-events`, `bulletin-all-non-event-sections`, `pdfs/two-column-bulletin.pdf`, `pdfs/three-column-bulletin.pdf` |
| Multi-church parish bulletin naming venues | `multi-church-bulletin` |
| Printed Mailchimp-style archdiocesan email | `mailchimp-style-newsletter`, `printed-email-style` |
| Event poster with a text layer | `text-poster`, `pdfs/single-column-poster.pdf` |
| Image-only poster (no text layer) | `image-only-poster`, `pdfs/image-only.pdf`, `posters/image-only-poster-without-text-layer.eml` + `posters/example-retreat-poster.png` |
| One-line notice | `single-event` |
| Forwarded email | `forwarded-email` |
| Reply with quoted text | `reply-quoted-text` |
| Recurring schedule | `recurring-first-friday`, `recurring-weekday-list`, `recurring-novena`, `recurring-advent-ambiguous` |
| Date range | `date-range` |
| Cancellation / postponement / change | `cancellation`, `postponement` |
| Un-headed event after a skipped section | `unheaded-event-after-intentions`, `unheaded-event-after-sick-list`, `unheaded-event-after-deceased` |
| Real-world client MIME variants | `gmail-multipart-alternative`, `outlook-html`, `apple-mail`, `windows-1252`, `mobile-email` |
| Relative and unusual date phrases | `relative-date`, `relative-date-coming-weekday`, `relative-date-end-of-month`, `relative-date-first-of-month` |
| Dotted and hyphenated time ranges | `time-range-dotted-am`, `time-range-dotted-bare`, `time-range-bare-hyphen` |
| Date/time formats parishes actually write | `real-format-dotted-date`, `real-format-ordinal-and-bare-time`, `real-format-after-mass-time` |
| Date or time written but not readable | `unreadable-date-and-no-time`, `unreadable-time-worded-clock` |
| Administrative / non-event mail | `admin-notice` |

A real example whose layout the synthetic route cannot reproduce faithfully — a specific column quirk, a
provider's real-world header shape, an unusual poster design — should be **derived from the original**
rather than hand-built, because an invented stand-in smooths over exactly the oddity the test exists to
catch. Such a fixture is committed in reduced, anonymised form, and its class of input is the row it
occupies in the table above — never anything that identifies a parish, person or household.

## Anonymisation rules

[`docs/fixture-anonymisation.md`](../../docs/fixture-anonymisation.md) is the binding guide. In short:

These rules apply to every fixture, invented or derived. "Reduced" is the operative word: keep what the test
needs — layout, column structure, header shape, wording pattern — and replace the rest.

1. **Replace identifying details.** Parish names (`Example Parish`, `St Fictional`), addresses
   `example.test` / `example.org`, and phone numbers strictly in the `021 555 01xx` range. A derived
   fixture's parish name is replaced even though the church address is itself public, because in a public
   repository the pairing identifies the source of a real sample.
2. **Remove** personal names, private addresses, medical detail, Mass-intention detail, bank/account
   and card details — never partially, not even a prefix or a masked fragment.
3. **Keep sensitive headings** (`Sick list`, `Mass intentions`, `Banking details`) with obviously
   invented placeholder names, because the section-skip tests depend on the headings being present.
4. **Rebuild `.eml` headers from scratch**: invented sender, synthetic subject, a fixed date with an
   explicit timezone, and a Message-ID on an `example.test` domain. Drop `Received` chains and routing or
   authentication headers that carry real infrastructure detail — they leak IP addresses, the sending
   domain and mail-provider accounts. Where a test genuinely needs a *header shape* (the screening
   fixtures cover `X-Mailer`, `Authentication-Results` and `Return-Path`), keep the header but point it at
   synthetic values, as `tests/fixtures/inbound-mail/` does.
5. **Re-create attachments** from synthetic content rather than redacting originals. Strip document
   metadata, comments, revision history, hidden layers, image EXIF/GPS and embedded thumbnails. PDFs are
   regenerated with `pdfs/generate.php` and poster PNGs with `posters/generate.php`; `*.pdf` and `*.png`
   are marked `binary` in `.gitattributes` so Windows line-ending conversion cannot corrupt the bytes.
   An image fixture must contain no text chunk, EXIF or GPS block — the encoder writes only `IHDR`,
   `IDAT` and `IEND`, so there is nowhere for metadata to hide.
6. **Commit only** the fixture pair — the `.eml` and its `.expected.json`, plus any regenerated
   attachment. Never commit the source message, redaction notes, screenshots or working copies.

Fixtures must also never carry personal data into anything downstream: skipped text must be absent
from expected output *and* from AI-provider input, because bulletins contain sick lists and
intentions, which are special personal information under POPIA.

## Review checklist for a new fixture pair

Run through this before committing:

- [ ] The filename names only the **generic sample type** — no parish, person or place.
- [ ] Grepped the new `.eml`, `.expected.json` and filename for the original sender domain, any real
      name, phone number, street address, bank number and identifying phrase.
- [ ] Every email address is `example.test` or `example.org`; every phone number is `021 555 01xx`.
- [ ] No partial, masked or truncated real identifier survives anywhere.
- [ ] No `Received` chain in the headers, and the `Message-ID` is on an `example.test` domain.
- [ ] Every sensitive heading the skip tests need is present, backed by invented placeholder names.
- [ ] `From` is present and `Date` carries an explicit timezone.
- [ ] Skipped-section text appears in **no** expected output and in no `source_snippet`.
- [ ] `existing_event` and directory snapshots contain invented data only.
- [ ] Every `known_failures` entry points at a genuinely open issue, and no tracked mismatch is left
      behind after its issue was fixed.
- [ ] The expectation is not weakened to match a bug; a wrong expectation changed in its own commit
      with a written reason.
- [ ] Provenance row added to the table above if the fixture covers a new class of input.
- [ ] For a **derived** fixture only: the committed files were opened and read, not just the working copy
      — `git diff --cached` inspected in full — and a person verified the anonymisation before merge.

## Rules for contributors

- Every parser bug report should add a fixture **first** (failing), then the fix.
- `composer fixture-score` prints a per-fixture and overall fields-correct table. When parsing rules
  improve, the score must not go down.
- The corpus is a test suite. Never remove, skip or weaken a fixture or expectation to make a build
  pass.
