# ADR 0004: Trust and confirmation model

- Status: Accepted
- Date: 2026-09-24

## Context
Anyone can email `events@adct.org.za`, and From addresses can be faked. Admin time is limited, and parishes should see how their event will look before it goes live. Email security scanners (e.g. Microsoft Safe Links) open links automatically.

## Decision
1. Every parsed candidate gets a **confirmation email** sent to the sender (or `Reply-To`). It contains a preview of each event as it will appear, with **Approve**, **Deny** and **Edit** links. One email covers all events found in one message, and each event can be approved on its own or all together.
2. Links open a page that shows the preview again with a button. **Only the POST from that button acts.** A GET request never changes anything.
3. Tokens: 32 random bytes, stored as a hash, single-use, tied to the candidate and email, with expiry (default 14 days for approve/deny, 30 minutes for login links).
4. Outcome by sender trust:
   - **Verified contact** of a parish → Approve **publishes immediately**.
   - **Unknown/pending sender** → Approve moves it to the **admin review queue**. Once an admin links the address to a parish, it becomes verified and future submissions auto-publish.
   - **Blocked** → ignored, no email sent.
   - **Monitored (secondary) source** → the parish's verified contacts get a "we found this on X, do you want it published?" email with the same flow.
5. No confirmation is sent to auto-replies, bounces, mailing lists or `noreply` addresses. The candidate goes to the admin queue instead.
6. SPF/DKIM/DMARC results are stored and shown to admins. A DMARC failure from a verified address sends the item to admin review.
7. Admins can override anything, and every decision is audit-logged.
8. Changes: replying to the confirmation email with corrections creates a new candidate that matches the same event (an update). The Edit link opens the portal.

## Consequences
- Parsing mistakes are caught by the people who know the event, at no extra admin cost.
- The system sends a small amount of email, which needs working SPF/DKIM for adct.org.za.
- A verified contact whose mailbox is compromised could publish. Admins can unpublish and block, and the audit log shows what happened.
