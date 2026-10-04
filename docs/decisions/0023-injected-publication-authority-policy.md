# ADR 0023: Publication authority is an injected policy, defaulting to review

- Status: Proposed
- Date: 2026-10-05
- Issue: [#71](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/71)
- Related: [#200](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/200) (open owner decision, deliberately not closed here), [ADR 0008](0008-approval-by-dean-or-archdiocese-reviewer.md)

## Context

[ADR 0008](0008-approval-by-dean-or-archdiocese-reviewer.md) point 4 promises that a change from a **verified parish contact** publishes immediately, and that a change from an unknown sender is treated like a new event and needs approval. #71 builds the half of that promise which is unambiguous — the trail, the notice, the revert — and it touches the half which is not.

That half is not ours to decide. #200 asks, in the owner's words, "may a verified contact publish a change, or must it still be reviewed?", and it is still open. A contact's change goes out on the public events page and into the ICS feed the moment it publishes, so the answer determines who can speak for the archdiocese publicly. Shipping the permissive reading because it is the more convenient one, on an unanswered question, would make a policy change look like an implementation detail.

There is also a second, quieter reason to keep this pluggable. "Verified" is a live directory fact, and it is withdrawn: a contact leaves a parish, an address is re-pointed, a role changes. Whatever answer the owner eventually gives, the check has to run against the directory at the moment of publication, not against a column that was written when the candidate was created. Hard-coding either half into `CandidatePublisher` would freeze both.

## Decision

1. **The question is a port, not a branch.** `PublicationAuthorityInterface` has one question — `allowsContactChange(array $row, SenderLookupResult $sender): bool` — and `CandidatePublisher` takes the implementation as a constructor argument. It does not know which policy is in force, and no fixture records which one was used, so no test can depend on the answer.
2. **The shipped default is the conservative one.** `ReviewRequiredPublicationAuthority` returns `false` for every contact change, without exception and without consulting the sender — a policy that has already decided must not be tempted by the answer it was given. The consequence is deliberate and is the point of the ADR: until the owner answers, a verified contact may send a change and may see it published by somebody else, and the change itself waits for a dean or a reviewer like anything else.
3. **Flipping it is one argument.** `Plugin::publisher()` passes `new ReviewRequiredPublicationAuthority()`. The permissive policy already exists as `VerifiedContactPublicationAuthority`, which resolves the sender through `ParishContactStoreInterface` and answers from a live trust lookup. When the owner answers, that argument changes and nothing else moves. #200 is not closed, pre-empted or annotated by this ADR.
4. **The lookup is live, and the label is not evidence.** `docs/data-model.md` is explicit that `approved_via = contact_change` on its own is not proof of a verified contact: the label is written by whichever code path set the candidate, and it stays behind if the verification is later withdrawn. So `resolveSender()` re-resolves `approved_by` against the directory at act time. A blank, oversized or unparseable address resolves to `UNKNOWN` with no parishes rather than raising — a candidate nobody can be shown to be authorised for has no verified contact behind it, and refusing it is the answer the caller wants.
5. **`ReviewRequiredPublicationAuthority` still resolves the sender.** It costs nothing while the answer is always no, and it means the day the owner flips the decision the sender has been arriving correctly all along. A policy that skipped the resolution would make the flip a second change rather than the first.

## Consequences

- A verified contact's change is reviewed rather than instant until the owner answers. If that turns out to be the wrong call, the cost of being wrong is a queue, not a public page — which is the whole reason the question is being asked rather than assumed.
- Nothing in the codebase, fixture or audit trail encodes the policy in force. That is the cost of the indirection and the reason the tests are written against the interface: two tests, one per policy, rather than a table of expected outcomes that would have to be rewritten the day the owner decides.
- `resolveSender()` runs on every contact-change publication even while the answer is always no. It is one directory lookup against data the plugin already has in memory per request; if it ever showed up in a profile, the place to fix it is the policy, which already has the call isolated in one method.

## Considered and rejected

- **A `bool` flag or an option.** Readable, but it makes the policy a value that can be changed on a live site by anyone who reaches the settings screen, and gives the audit trail no way to say who decided. The owner is one person; the answer belongs in code where a pull request shows it.
- **Letting `CandidatePublisher` decide for itself, with the check inline.** This is the shape the code had before this ADR. It is the one that would have quietly shipped the permissive reading, because the permissive reading is what the code was already doing.
- **Closing #200 in the same pull request.** The question is the owner's and the answer belongs to the archdiocese, not to the implementer. This ADR records that a decision is pending and makes it cheap to answer; it does not answer it.