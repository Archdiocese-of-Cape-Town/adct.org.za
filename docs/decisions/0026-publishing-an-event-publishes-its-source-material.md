# ADR 0026: Publishing an event publishes its source material

- Status: Proposed
- Date: 2026-10-26
- Issue: [#172](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/172)
- Supersedes: [ADR 0025](0025-media-library-copy-is-the-promotion.md), whose promotion gate is described below

## Context

[ADR 0025](0025-media-library-copy-is-the-promotion.md) settled one half of issue #172: copying an intake file into the media library *is* the publication, because a WordPress attachment is world-readable the moment its row exists. Nothing downstream can revoke it. That part stands and is not revisited here.

ADR 0025 also settled the other half — **when** that copy happens — by making it an explicit operator action, separate from publishing the event. Its reasoning was that a parish which emails a bulletin has not thereby consented to that bulletin becoming world-readable, and that an approver focused on the date and venue will skip a checkbox on the review screen.

The project owner has overruled that second half:

> promotion is a step too complicated, i just want it simple, published or not, the source of the events are public, by sending an email they assuming it's public information as a whole.

The owner is the person who can settle this question for this project. The reasoning in ADR 0025 is a risk judgement, and it is the owner's judgement to make. This ADR records the reversal so the reasoning that lost is not silently deleted, and so a future reader who agrees with it can see it was considered rather than missed.

## Decision

**Publishing an event publishes the source material that arrived with it.** There is no promotion gate, no selection, no default list and no setting, because there is nothing left to decide once the event is published.

Concretely, `PublishedSourceMaterialPromoter` runs from `WordPressPublicationStore::afterCommit()` on the fresh-publish path, and copies every eligible file belonging to the message the candidate was parsed from.

Four consequences are decisions, not accidents:

- **The role is derived from the file type**, from `SourceMaterialRole::rolesFor()`, never from a caller's choice. An image cannot be filed as a bulletin and a PDF cannot be filed as a poster. A type no role accepts is skipped, not given a fallback. This keeps the allowlist in exactly one place.
- **The hook runs after `COMMIT`, not inside `publish()`.** Once the transaction is committed there is nothing to roll back, so a failed copy cannot unpublish a correct event — which is the outcome the issue's acceptance criteria require.
- **The hook fires only on a fresh publish**, never on the already-published short-circuit. The store tells operators to re-publish a published candidate in order to repair a listing generation; promoting again would re-copy every file and demote the first poster to a document. The guard is per candidate, not per message, because one bulletin email yields several candidates sharing a `message_id` and each must publish its own.
- **Nothing is thrown.** Anything escaping `afterCommit()` lands in the store's `$committed` catch branch and is reported to the operator as *"its listing cache could not be refreshed"*, telling them to re-publish a candidate that is already live. A promotion fault escaping would therefore masquerade as a cache fault. Faults are caught and `error_log`ged instead.

## The manual route stays

The review-queue and event-editor add controls are **not** removed. They remain the repair hatch for a promotion that did not complete — a temporarily unwritable uploads directory, a disk quota, a file that vanished from the intake store — and they keep their capability and nonce checks.

Making the manual route unnecessary *in the ordinary case* is what removes the step the owner objected to. Removing the ability to fix a partial failure is not simplification, it is losing a capability with nothing gained.

## Consequences

- Every published event from an email notice now carries its poster or bulletin automatically. That is the point.
- A wrong file on an event is corrected by removing it, which has always been available. Removing is a visibility change, not a deletion, so it can be undone by adding the file back.
- The media-library copy is still not reversible at the filesystem level. ADR 0025's limitation stands unchanged.
- **An assumption, stated rather than decided:** the actor on an automatic promotion is resolved by `ActorResolver` at write time, so it attributes to whichever user context is active — the approver, the cron user, or the system actor. The owner has not chosen between these. Every promotion is still recorded against the intake row it came from, so an audit reader can see exactly which file became public.
- **Retention is unchanged and remains open.** Media-library copies are exempt from intake retention, so the original is kept until an operator deletes the attachment. Issue #172 does not settle how long that is, and this ADR does not invent a policy.

## Alternatives considered

- **Keep the gate and make it easier** (a "publish with source material" button). Rejected: this is still a second decision at the moment of approval, which is precisely what the owner called too complicated.
- **Reuse `adct_pi_attachments.status` as a promotion marker** to avoid re-copying. Rejected: `WordPressAttachmentExtractionStore::recordResult()` and `WordPressImageOcrExtractionStore::recordResult()` both write that column and `findPendingImagesForMessage()` reads `status = 'pending'`, so hijacking it would collide with OCR and attachment extraction.
- **Store intake attachment ids in `SourceMaterialReference`.** Rejected: that meta is read by the public renderer, and intake row ids do not belong in a public payload.
- **Promote inside `publish()`, inside the transaction.** Rejected: a copy failure would then roll back a correct publication. The issue explicitly wants the event published with its material, not neither.