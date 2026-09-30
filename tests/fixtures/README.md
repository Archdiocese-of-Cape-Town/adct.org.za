# Parser fixtures

Invented, anonymised test inputs for the parsing pipeline. Every file here is synthetic.

> **None of these fixtures are real parish samples.** The 13 real attachments reviewed in September
> 2026 stay in approved private storage and are never committed, copied into a chat, or sent to an
> automated tool. The fixtures in this directory are *structural stand-ins*: they reproduce the
> **shape** of each real class of input with entirely invented text. See the provenance table below
> and [`docs/fixture-anonymisation.md`](../../docs/fixture-anonymisation.md), which is the binding
> guide. The "at least 10 anonymised real samples" criterion of
> [#19](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/19) is therefore **not met
> yet** and needs a person with access to the private originals.

## Layout

| Path | What it holds | Runner |
|---|---|---|
| `emails/<name>.eml` + `emails/<name>.expected.json` | The golden event-parsing corpus | `tests/Unit/Parsing/EmailFixtureCorpusTest.php` |
| `inbound-mail/<name>.eml` + `.expected.json` | Header-signal screening (bounces, no-reply, out-of-office, spoofed auth results) | `tests/Unit/Mail/RawMessageInspectorTest.php` |
| `pdfs/*.pdf` | Re-created PDFs for text extraction and column ordering | `tests/Unit/WordPress/Pdf/PrinsFrankPdfTextExtractorTest.php` |
| `directory.json` | Shared invented parish/venue/sender directory | many directory-resolution fixtures |
| `change-notice-directory.json` | Directory used by cancellation/postponement fixtures | `cancellation`, `postponement` |
| `confirmation-email/` | Local HTML/plain-text snapshots of the submitter preview | `ConfirmationEmailRendererTest` |
| `matching/notices.json` | Repeat-matching scenarios | `EventNoticeMatcherTest` |
| `pdfs/generate.php` | Regenerates the committed PDFs | run by hand: `php tests/fixtures/pdfs/generate.php` |

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
[`docs/testing.md`](../../docs/testing.md) §"Tracked fixture failures".

Skipped-section text must never appear in expected output, and no `source_snippet` may carry it.

## Provenance: the real class of input each stand-in models

These come from the sample review in [`docs/parser-samples.md`](../../docs/parser-samples.md) §"What
the samples are": 8 bulletins, 2 text-layer posters, 3 image-only posters.

| Real class of input (from the private review) | Stand-in fixture(s) here |
|---|---|
| Weekly parish bulletin, 2–3 columns, text layer | `bulletin-multi-event-skip-sections`, `bulletin-numbered-events`, `bulletin-all-non-event-sections`, `pdfs/two-column-bulletin.pdf`, `pdfs/three-column-bulletin.pdf` |
| Multi-church parish bulletin naming venues | `multi-church-bulletin` |
| Printed Mailchimp-style archdiocesan email | `mailchimp-style-newsletter`, `printed-email-style` |
| Event poster with a text layer | `text-poster`, `pdfs/single-column-poster.pdf` |
| Image-only poster (no text layer) | `image-only-poster`, `pdfs/image-only.pdf` |
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
| Administrative / non-event mail | `admin-notice` |

A real example that **cannot** be anonymised is represented by a synthetic stand-in of the same
structure, and the real class of input is recorded in the table above — without identifying anyone.

## Anonymisation rules

[`docs/fixture-anonymisation.md`](../../docs/fixture-anonymisation.md) is the binding guide. In short:

1. **Invented identities only.** Parish names (`Example Parish`, `St Fictional`), addresses
   `example.test` / `example.org`, and phone numbers strictly in the `021 555 01xx` range.
2. **Remove** personal names, private addresses, medical detail, Mass-intention detail, bank/account
   and card details — never partially, not even a prefix or a masked fragment.
3. **Keep sensitive headings** (`Sick list`, `Mass intentions`, `Banking details`) with obviously
   invented placeholder names, because the section-skip tests depend on the headings being present.
4. **Rebuild `.eml` headers from scratch**: invented sender, synthetic subject, a fixed date with an
   explicit timezone, and a non-identifying Message-ID. Never carry over `Received`, authentication,
   routing or provider headers.
5. **Re-create attachments** from synthetic content rather than redacting originals. Strip document
   metadata, comments, revision history, hidden layers, image EXIF/GPS and embedded thumbnails. PDFs are
   regenerated with `pdfs/generate.php`; `*.pdf` is marked `binary` in `.gitattributes` so Windows
   line-ending conversion cannot corrupt the text layer.
6. **Commit only** the synthetic `.eml` and its `.expected.json`. Never commit source messages,
   redaction notes, screenshots or working copies.

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
- [ ] Every sensitive heading the skip tests need is present, backed by invented placeholder names.
- [ ] `From` is present and `Date` carries an explicit timezone.
- [ ] Skipped-section text appears in **no** expected output and in no `source_snippet`.
- [ ] `existing_event` and directory snapshots contain invented data only.
- [ ] Every `known_failures` entry points at a genuinely open issue, and no tracked mismatch is left
      behind after its issue was fixed.
- [ ] The expectation is not weakened to match a bug; a wrong expectation changed in its own commit
      with a written reason.
- [ ] Provenance row added to the table above if the fixture models a new class of input.
- [ ] For a **real-derived** fixture only: a person verified the anonymisation before publication.

## Rules for contributors

- Every parser bug report should add a fixture **first** (failing), then the fix.
- `composer fixture-score` prints a per-fixture and overall fields-correct table. When parsing rules
  improve, the score must not go down.
- The corpus is a test suite. Never remove, skip or weaken a fixture or expectation to make a build
  pass.
