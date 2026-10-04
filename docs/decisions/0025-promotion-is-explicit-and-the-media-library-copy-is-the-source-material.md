# ADR 0025: Promotion is explicit, and the media-library copy is the source material

- Status: Proposed
- Date: 2026-10-19
- Issue: [#172](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/172)
- Related: [ADR 0017](0017-library-first-protocol-and-format-handling.md), [ADR 0018](0018-client-side-ocr-for-image-posters.md), [ADR 0016](0016-pre-release-schema-changes.md)

> **Numbering note.** Issue #172 asks for "ADR 0017". ADR 0017 is already
> [`Library-first protocol and format handling`](0017-library-first-protocol-and-format-handling.md)
> and is **Accepted** — it is about protocol and format libraries, not about
> promotion, and rewriting or superseding an accepted ADR to make room for an
> unrelated one would be exactly the thing those records exist to prevent. This
> decision is therefore recorded as **0025**. The number in the issue is a
> slip, not a request to change an accepted record.

## Context

Parish event notices arrive by email with the poster image or the parish
bulletin attached. The plugin stores those attachments privately, keyed to the
inbound message, and parses the text around them. Nothing in the pipeline ever
connected a published event back to the file it came from: a candidate recorded
the attachment *filenames* it saw, with no ids, and an event post recorded
nothing at all.

The public single-event page has been rendering a poster all along, through
`has_post_thumbnail()` and a `<figure class="adct-event__poster">` in
`templates/single-adct_event.php`, and `PublicEventPage::jsonLd()` has been
emitting an `image` property from the same place. **That path has always been
empty**, because nothing in the plugin ever set a featured image. So the gap is
not a new feature bolted onto a template; it is a template waiting for a
decision.

The tempting implementation is to close the loop automatically. When a candidate
is published, copy any image attachment up to the media library, parent it to
the event, and set it as the featured image. It is four lines, it makes the
poster appear on every single event the first time, and it is wrong for four
independent reasons:

1. **The files are attacker-controlled.** A filename arrives from a stranger's
   mailbox and lands in `wp-content/uploads`. Whatever copies it to the web-
   servable directory has to be defensible against `../../wp-config.php`, a
   `.php` extension, and a name in a script that no `sanitize_file_name()`
   implementation fully anticipated. An automated path hands that to every
   message that ever arrives, including the ones nobody ever opens.
2. **It publishes by accident.** "The publisher confirmed the event" and "the
   publisher confirmed *this picture* is the parish's picture" are different
   permissions. A poster embedded in a bulletin may be a sponsor's advert, a
   stock image, or a photograph of the person who sent it. Attaching it to the
   archdiocese's own page for the life of the media library is a judgement call,
   and the person who approved the *text* is not necessarily the person who
   should make it.
3. **The privacy stance is not ours to re-open.** A POPIA enquiry asks who
   made this material public. The answer has to be a named human and a
   timestamp. An automatic copy has neither.
4. **It has to be undoable.** If the wrong poster goes up, the reversal must not
   depend on the parish mailing it again.

`docs/data-model.md` also already claimed an "image attachment id" column in
`event_candidates.fields`. No code has ever populated it. That is a second,
smaller instance of the same temptation: the documentation described a design
that nobody had to justify at the time.

## Decision

1. **Promotion is always explicit and human, for every MIME type, with no
   exception path.** Nothing is copied automatically, for any type, ever. There
   is no filter, no option, no capability-gated shortcut and no default that
   produces a copy without a person pressing a control. `CandidatePublisher` is
   structurally unable to promote: the decision is behind a port
   (`PromotionDecision`), and the WordPress adapter that performs the copy is
   only reachable from two `admin_post_` handlers.
2. **The offer appears in two places, and the second is mandatory.** The review
   queue gets a control rendered visually separately from the review/publish
   button and defaulting to nothing promoted; the event editor gets an add/remove
   control so the decision can be taken or reversed *after* publication. A queue
   -only offer would mean the decision can never be changed, and an editor-only
   offer would mean it cannot be made at the moment of review.
3. **Parentage alone is not enough, so role and order are stored too.** The copy
   gets `post_parent` set to the event post — which is what makes it a child in
   WordPress's own terms — and the ordered list of roles is recorded in the
   `source_attachment_ids` post meta key, each entry naming the media id, the
   role (`poster`, `bulletin` or `document`) and the original filename.
   `post_parent` records *that* a file belongs to an event; it cannot record
   which of three files is the poster, and WordPress offers nowhere else to put
   that. Both are written together and read together.
4. **The poster role is what sets the featured image.** Promoting with role
   `poster` calls `set_post_thumbnail()`, which fills the figure and the JSON-LD
   `image` that already existed. `bulletin` and `document` are deliberately not
   featured images — a PDF thumbnail as an event's Open Graph image is worse than
   no image at all.
5. **The stored name is generated, and no path component may derive from the
   uploaded name.** The copy is written as `adct-source-<hex>.<ext>`. The
   submitted filename is kept as attachment metadata and escaped on every
   render — it is display text, never a path. This holds for the original name
   *and* for any part of it: directory, extension or character.
6. **Unpromoted material stays unreachable.** A media-library copy is created at
   promotion time and not before, and the front end reads *only* through the
   ordered post meta. It never runs a broad `get_children()` over the event, even
   though `post_parent` would make that look like a convenient query — that
   query is exactly the one that would publish whatever happened to be on the
   server, and there is a test that fails if it appears.
7. **Removal is a visibility change.** Removing an item detaches it from the
   event, clears the featured-image role if it held it, writes the audit row —
   and leaves the stored file exactly where it was. Re-promoting takes it back.
   Nothing a reviewer approved is destroyed by a change of mind.
8. **Every promotion and every removal writes an `adct_pi_audit_log` row** with
   the acting user, the event and the attachment. No promotion means no row and
   no audit trail, which is what makes the POPIA answer possible.
9. **The filesystem copy stays outside the publish transaction.**
   `WordPressPublicationStore::publish()` wraps its work in `START TRANSACTION`
   / `COMMIT`; `wp_handle_sideload()` performs file I/O that a rollback cannot
   undo. Only the `post_parent` re-parenting folds into the existing
   transaction. A failed promotion leaves the event published with no source
   material and says so — it never rolls back the publication, and never leaves
   a half-copied file behind.
10. **`src/Core` stays WordPress-free.** The value object (`SourceAttachment`)
    and the promotion decision are plain PHP behind interfaces; the copy itself
    is a WordPress adapter. `CandidatePublisher` therefore remains free of
    WordPress calls, as required since #20.
11. **A media-library copy is exempt from intake retention.** The retention
    window exists because an inbox is ephemeral. A promoted copy is not: it is a
    site asset on a public page. Retention cannot reach it, and this is
    structural rather than a flag — `ProtectedInboundMailStorage` accepts exactly
    one shape of name (64 hex characters plus a known extension), the copy is
    named `adct-source-…`, and it lives in the uploads directory rather than the
    private inbound one. Deleting a parish's email after the window must not
    break or unpublish the approved public material.
12. **HEIC and HEIF are excluded from promotion.** They pass the storage
    allowlist, so they are stored and offered as intake material, but no
    mainstream browser renders them. The review queue shows them as unavailable
    rather than offering a promotion whose result would be a broken image on the
    public page.

## Consequences

- Events without a poster keep the empty state they have always had, and adding
  one is a deliberate two-click act that leaves an audit row naming the person.
- Reviewers must make a second decision per candidate with an image. That is the
  cost of the guarantee in points 1–5, and it is the whole point of the ADR.
- The `source_attachment_ids` meta key is a second, ordered record alongside
  `post_parent`, and the two have to agree. That duplication is deliberate: the
  first expresses ownership, the second expresses role and order, and
  `PostParent` alone cannot be made to say which of three files is the poster.
- `docs/data-model.md` no longer documents an `image_attachment_id` on
  `event_candidates`. The column was removed from the documentation rather than
  populated, because the id does not exist until a human promotes something —
  writing it at candidate-creation time would reintroduce the automatic
  promotion this ADR forbids, one column earlier.
- **HEIC posters need a different path.** A parish whose only notice is a HEIC
  photo will get an event with no poster and an explanation of why. Conversion
  (server-side or client-side, [ADR 0018](0018-client-side-ocr-for-image-posters.md)
  territory) is out of scope here and would need its own decision.

## Considered and rejected

- **Auto-promote images on publication, behind a capability or a filter.** Four
  lines, and it makes the first parish with a sponsor's advert in their bulletin
  decide our content policy for us. Rejected on the same grounds as ADR 0018's
  server-side OCR: the automation is convenient for the implementer and
  expensive for the parish.
- **Populate `event_candidates.fields.image_attachment_id` as planned in the
  data model.** The column only has a value after promotion, so filling it at
  candidate-creation time means copying the file at candidate-creation time.
  Correcting the documentation is the honest option; keeping the column and
  leaving it unpopulated is not.
- **Read promoted material with `get_children(['post_parent' => $event])`.**
  One line, no meta key, and it is the query that makes `post_parent` necessary
  in the first place. Rejected because it renders whatever is attached, promoted
  or not — which is precisely the guarantee being given up.
- **Make the media copy itself the intake original** — move rather than copy, so
  there is only ever one file. Breaks the audit story of the promotion (there
  would be nothing to copy from) and loses the ability to remove without deleting.
- **Serve bulletins from a private download endpoint or by streaming.** Both
  are deferred in #172 and both need their own access-control decision. The
  promoted copy is an image or a public asset for now; a PDF behind an
  authenticated route is a different feature with a different threat model.
- **A `bool` setting for "promote automatically".** Same objection as ADR 0023's
  rejected flag: it makes a publication policy reachable from a settings screen
  by anyone who gets there, and it gives the audit trail no way to say who
  decided.
