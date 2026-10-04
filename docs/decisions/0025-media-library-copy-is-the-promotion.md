# ADR 0025: The media-library copy is the promotion, and promotion is always a human act

- Status: Proposed
- Date: 2026-10-06
- Issue: [#172](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/172)

## Context

Parish bulletins arrive as email attachments and are stored in a private intake folder. Issue #172 asks for a publisher to be able to make an event's original source material — the poster image, or the bulletin PDF it arrived as — viewable alongside the published event, so a visitor does not have to take the event's word for its own details.

The obstacle is not the display. It is that copying a file into the media library makes it public at that moment, and no later step can revoke it. The issue states the reason plainly: a parish posting a bulletin by email has not thereby consented to that bulletin becoming world-readable, which is why promotion is a separate deliberate step rather than a side effect of publishing.

So the design question is not "how do we render a PDF link". It is "what is the smallest act that makes a file public, and who may perform it".

Three properties of the existing codebase make this delicate:

- **WordPress media-library attachments are public by default.** Their URLs are guessable from the file name, are not capability-checked, and are not served through any plugin code. Therefore *copying a file into the media library is itself the publication*. There is no later step that could revoke it, and no "staged but not yet public" state to get wrong. This is the single most important fact in this ADR.
- **`post_parent` is not a permission.** Grouping a copy under its event post makes the media library tidier and makes the event's media picker filter correctly, but it does not restrict anything. Neither does an ordered post-meta key. Both are bookkeeping; the copy on disk is the disclosure.
- **The single-event page already renders a poster from `has_post_thumbnail()`** in `templates/single-adct_event.php`, and `PublicEventPage::jsonLd()` already reads it. That code path has always been empty, because nothing in the plugin ever set a featured image from an inbound attachment. Filling it is the cheap half of #172 and the reason the issue exists at all.

The issue also records that promotion must be offered in two places — the review queue and the event editor — and states why: approval answers "is this event correct and publishable?", which is not an archival decision about the source bulletin. An approver focused on the date and venue will skip a checkbox on that screen. A default of nothing promoted, plus a later correction path, is safer in practice than a hard gate at a moment of unrelated attention.

## Decision

1. **Promotion is an explicit, human action. Nothing is promoted automatically, ever.** Publishing a candidate never copies an attachment. There is no configuration option, no default and no MIME type for which promotion is implicit. A missing promote selection means nothing is promoted, and that is the state every candidate starts in.

2. **A media-library copy *is* the promotion.** `WordPressSourceMaterialCopier` performs `wp_handle_sideload` at the moment an operator promotes, with `post_parent` set to the event so the media library groups it and the event's media picker filters to it. The copy runs outside the publication transaction (see point 6).

3. **The ordered `source_attachment_ids` post meta records what was promoted, in what role, in what order.** Parentage alone cannot say which file is the poster, so the meta carries `attachment_id`, `role` (`poster` / `bulletin` / `document`) and the parish's submitted filename. The submitted filename is attacker-controlled and lands in the uploads directory, so the **stored file gets a plugin-generated name** and no path component is ever derived from the uploaded name; the original survives as metadata and is HTML-escaped on every render.

4. **The front end reads that meta and nothing else.** `PublicEventPage::sourceMaterial()` and `PublicEventListing::cardSourceMaterial()` both go through `SourceMaterialStoreInterface`, never a `get_children()` over the event's media. An attachment that exists in the intake store but was never promoted is unreachable from every public page, feed and REST response — and, because of point 2, does not exist in the media library either. There is no partially-published state.

5. **Promoting an image with role `poster` also calls `set_post_thumbnail()`.** This fills the pre-existing poster figure and the JSON-LD `image` field. The listing card deliberately reads `has_post_thumbnail()` rather than doing a `poster`-role lookup, so that clearing the featured-image role empties the card and the single-event figure together, by the same code route. A promoted poster that was never the featured image is *linked* on the card, never embedded — otherwise releasing the featured-image role would not actually hide it.

6. **The filesystem copy stays outside the publication transaction.** `WordPressPublicationStore::publish()` wraps its work in `START TRANSACTION` / `COMMIT` and writes a post. `wp_handle_sideload` does filesystem I/O that a database rollback cannot undo. A failed promotion therefore leaves the event **published with no source material** and surfaces an admin notice, rather than rolling back the publication or leaving a half-copied file. The `post_parent` re-parenting folds into the existing transaction; only the copy is outside it.

7. **Removal is a visibility change, not a deletion.** Removing a source item drops it from the meta and releases its featured-image role. It does **not** delete the stored original: the raw file is evidence and the existing retention settings govern it. Removal is reversible by re-promoting. Media-library copies are exempt from intake retention, because once promoted they are a site asset rather than inbox ephemera — deleting the private original after the retention window must not unpublish anything.

8. **Every promotion and every removal writes an `adct_pi_audit_log` row** naming the acting user, the event and the affected attachment, so "who made this bulletin public" is answerable after the fact. The meta records *what* was promoted, not *who* promoted it, so the audit table is the only place that answer lives.

9. **The event editor's add/remove control checks a capability and verifies a nonce.** Unauthorised or forged requests are refused with 403 and write no audit row. `source_attachment_ids` is refused over REST, so the block editor's meta endpoint cannot be used to bypass the checked screen.

## Consequences

- There is no way to un-publish a file that has been promoted *and then copied*. Detaching the attachment from the event and clearing the meta removes it from every surface the plugin renders, but the file remains in the media library and its URL remains valid until an operator deletes the attachment itself. This is a real limitation of choosing the media library as the store, and it is the price of not writing a private download endpoint (Option C, explicitly out of scope for #172). It should be said plainly rather than papered over: **for a genuinely sensitive file, promoting is not reversible.**
- Because the copy is what publishes, there is no cheap way to add a second, stricter visibility tier later without changing the storage story. A future "public link" tier would need the private download endpoint that #172 declined.
- Two screens can now promote. They are deliberately kept consistent by sharing `SourceMaterialStoreInterface` and `AttachmentRepository::findPromotableForMessage()`, because two repositories would let the two screens disagree about what is promotable.
- Retention cleanup must actively *not* touch promoted copies. `RetentionCleanupVsMediaLibraryTest` drives the real `RetentionCleanupJob` against a filesystem stand-in to prove it.
- The theme-override half of AC6 ("both render correctly with a theme that overrides `templates/single-adct_event.php`") is **not resolved by this ADR**. Overriding the template drops the plugin's poster figure and source list with it, and there is no filter, shortcode or documented re-copy policy that restores them. That is an open gap, recorded in the PR rather than answered here, because answering it means choosing between a filter surface and a documented re-copy obligation, and that choice is the project owner's.

## Considered and rejected

- **Promoting automatically on publish, for images only.** Images are less revealing than PDFs, so this looked like a reasonable narrowing. It still publishes a parish's faces and children's names without anyone deciding to, and the default becomes the disclosure.
- **A "copy but keep private" state.** WordPress has no such state for media-library attachments: the URL works the moment the row exists. Any design that needs it has to stop using the media library, which is the Option C work #172 declined.
- **Streaming from the private intake folder instead of copying.** Declined in the issue as Option B. It would avoid the media library entirely, but it puts a download endpoint in front of private storage and needs its own authorisation story; if the media library proves unworkable this should be raised, not quietly worked around.
- **Letting the front end query the event's media.** `get_children()` would make every attachment parented to the event reachable, promoted or not. This is the specific failure mode the ordered meta exists to prevent.
- **Deleting the stored original on removal.** Destroys the evidence a parish bulletin is, and makes removal irreversible. Retention already owns that file.