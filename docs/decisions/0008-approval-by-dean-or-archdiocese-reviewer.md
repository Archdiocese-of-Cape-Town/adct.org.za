# ADR 0008: Approval by dean or archdiocese reviewer

- Status: Accepted
- Date: 2026-09-24
- Supersedes: point 4 of [ADR 0004](0004-trust-and-confirmation-model.md), where a verified sender's confirmation published immediately

## Context
In ADR 0004, a known (verified) parish sender could publish just by confirming their own event. After the review, the archdiocese decided that **every new event must be approved before it goes live**. Deans already oversee the parishes in their deanery, so they are natural approvers. Archdiocese staff must also be able to approve, so no event waits on one person.

## Decision
1. **Two steps for every new event:**
   1. **Submitter confirms** the emailed preview (ADR 0004, points 1–3). This proves they sent it and catches parsing mistakes.
   2. **One approver approves.** The approver is the **dean of the parish's deanery** (or another approver assigned to that deanery) **or any archdiocese reviewer**.
2. **Parallel queues, first to act wins.** After confirmation, the candidate shows up in the deanery approvers' queue and the archdiocese reviewers' queue **at the same time**. The first Approve or Reject decides it. Everyone else sees who acted and when, and their emailed links show "already decided by …".
3. **Self-approval is allowed.** If the submitter is an approver for that parish (a dean for their own deanery, or an archdiocese reviewer), their confirmation also counts as approval, and the event publishes in one step. The audit log records `approved_via = self`.
4. **Changes to published events by a verified parish contact publish immediately.** This covers new times, venues, details and cancellations. Approvers get a "changed" notice with a before/after summary and a one-click **Revert** or **Unpublish**. Changes from unknown senders or monitored sources are treated like new events and need approval.
5. **Sender trust still matters, but it no longer skips approval.** A verified sender gets the parish and venue filled in automatically and can make instant changes (point 4). For an unknown sender, approvers see an "unknown sender" warning and can link the address to a parish.
6. **Monitored sources** ("we found this on X, do you want it published?"): the parish contact's "yes" counts as the submitter confirmation, and approval follows as usual.
7. **Parishes without a deanery** (movements, archdiocesan offices, groups) go to archdiocese reviewers only.
8. **Approver notifications:** each approver gets an email per item with the preview and **Approve / Reject / Edit** links. The links use the same safe pattern as ADR 0004: a GET shows a page and only the POST acts, and tokens are hashed and single-use. Each approver can switch to a **daily digest** instead. Deans never need to use wp-admin.
9. **Reminders:** if nobody has acted after N days (default 3), each queue gets one reminder. This can be switched off globally or per approver.
10. Admins with `adct_pi_review` can always override: approve, reject, revert or unpublish. Every decision is audit-logged.

## Consequences
- Nothing new is published without a second pair of eyes, and approval is spread across the deans instead of falling on a central admin.
- Parishes wait a little longer for new events to go live. Parallel queues and reminders keep this short.
- Deans need accounts (WordPress users with the Deanery approver role, [ADR 0007](0007-auth-with-wordpress-users-and-magic-links.md)) and deanery assignments in the directory.
- Instant changes mean a compromised parish mailbox could alter published events. Approvers are told straight away and can revert, and the change history (`event_changes`) keeps the previous version.
- "First to act wins" needs an atomic state change (e.g. `UPDATE … WHERE status = 'awaiting_approval'`), so two approvers clicking at once can't both act.
