# ADR 0026: Publication discloses eligible source material

- Status: Proposed
- Date: 2026-10-31
- Issue: [#252](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/252)
- Related: [ADR 0025](0025-promotion-is-explicit-and-the-media-library-copy-is-the-source-material.md), [ADR 0008](0008-approval-by-dean-or-archdiocese-reviewer.md)

## Context

The owner directed that event publication be the disclosure switch: sending
an event notice means its source material is intended for public use, without
a second promotion decision. This replaces the explicit-only policy proposed
in ADR 0025, not its path safety, authorization or retention protections.
ADR 0025 remains an unchanged historical proposal; this record is not accepted
on the owner's behalf. The unmerged alternate implementation in #243 is prior
art only; this change builds on the interfaces merged in #251.

## Decision

- After an authorized candidate publication commits, copy all eligible stored
  attachments from its message through the existing source-material adapter.
  This applies to dean, reviewer and self approval and approved changes, not
  merely to one admin button. Manual events without a source message copy
  nothing. The policy on verified-contact changes (#200) is unchanged.
- PDFs default to bulletin; the first image becomes the poster only when the
  event has no featured image. Other images are documents, so no source is lost
  by repeatedly replacing the poster. JPEG, PNG, WebP and PDF remain the closed
  allowlist. HEIC/HEIF, raw email and unsupported types stay private.
- Use generated filenames and the protected storage resolver. Sideload a
  temporary copy because WordPress moves its input: the private original must
  survive for other events parsed from the same message. Check actual file
  size before copying. Public rendering still reads only ordered event meta.
- Attribute automatic disclosure to the candidate's recorded, validated
  approver, not the browser session or a background system actor. Each existing
  promotion audit row additionally records `candidate_id` and `automatic`.
  Manual recovery/removal keeps the authenticated actor resolver.
- Isolate copying from the publication transaction. A per-event database
  advisory lock in the source store serializes automatic copying and manual
  add/removal without waiting in a web request. The publication store delegates
  without acquiring a second lock; same-connection reentrant mutation is refused,
  while a lock on another event does not interfere.
  Contention reports an already-published event needing retry. Check the current
  source candidate after obtaining the lock so a superseded attempt does not
  copy stale material. Release the connection-scoped lock after processing.
  Invalidate the event and listing caches even when source processing fails:
  the committed event must still be visible publicly.
- Persist a private, per-event/per-intake-attachment successful-publication
  history key. Retries skip successful files, including files subsequently
  removed or replaced. Failed files have no success marker and remain retryable.
  Copy failures do not roll back the event or prevent other files from copying;
  record an escaped operator-facing warning in event meta and log only IDs and
  exception class. Adapter-level/read failures explicitly report committed
  publication rather than pretending to be listing-cache failures.
- Keep nonce/capability-protected recovery and removal controls. Removal hides
  the item from the event, not from the media library or a previously shared
  public URL. Automatic retry must not restore it; manual re-add is deliberate.
  A recording/audit failure restores the prior source state and removes the
  newly created media copy so retry cannot leave unaudited public references.
  Failure warnings survive an update from another source message. Private
  recovery metadata records the failed attachment's message and candidate;
  the editor verifies those against the candidate lookup and stored file row
  before offering it or accepting a recovery POST. A warning alone is not
  attachment authorization. Only recovery of that file clears its warning.

## Consequences and remaining assumptions

Publication includes every eligible attachment on the message, even when one
bulletin produced several event candidates; it does not infer per-event file
relevance. That follows the owner's whole-notice direction. A distinct public
copy per event is retained; retry of the same event does not duplicate it.

Existing media-library copies remain exempt from intake retention. Removal is
not erasure: already-public URLs remain accessible until an authorized operator
deletes the media asset separately. No retention duration for these site assets
is newly decided here. The owner still needs to formally accept this proposal
and confirm operational publication notices and public-media retention policy.
No schema migration, dependency, server OCR, paid service or live-site change is
introduced.
