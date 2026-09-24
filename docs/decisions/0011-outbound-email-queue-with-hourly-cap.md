# ADR 0011: Outbound email queue with an hourly cap

- Status: Accepted
- Date: 2026-09-24

## Context
xneelo allows **500 emails per hour** (or one email to at most 500 recipients) per hour, with messages of up to 30 MB. That limit most likely covers the whole hosting account, so it is shared with the rest of adct.org.za (forms, WordPress notices, newsletters). The plugin sends several kinds of email:

- login links
- confirmation emails to submitters
- approver notices
- change notices
- reminders and digests

A burst could reach the limit, for example a weekly reminder run to about 150 parishes plus a batch of bulletins being confirmed. The host would then reject or defer mail, and login links would be delayed along with everything else.

## Decision
1. All plugin email goes through a **mail queue table** (`adct_pi_mail_queue`) instead of calling `wp_mail()` directly.
2. A **configurable hourly cap** applies, **default 100 per hour**, which leaves room for the rest of the site. The queue sender counts what it has sent in the last hour and stops at the cap.
3. **Priorities:**
   1. Login links and submitter confirmations (sent at once if under the cap, even outside a cron run).
   2. Approver notices and change notices.
   3. Reminders and digests (spread out over the day).
4. Messages to several people are sent **one per recipient**. There are no big BCC lists, and approver notices are grouped into one email per approver per run.
5. Outbound emails carry **no attachments**; they link to previews instead. Inbound messages are accepted up to the host's 30 MB limit, and individual attachments up to 15 MB.
6. Failed sends are retried with backoff. After 5 failures the message is marked failed and shown on the health dashboard.
7. Reminders are staggered and send only when there is something to act on (no empty digests).

## Consequences
- A busy hour delays low-priority mail, but login links and confirmations still go out.
- The health dashboard shows how many messages are waiting and the oldest one's age.
- Tests cover cap enforcement, priority order, grouping and retries without sending real mail.
