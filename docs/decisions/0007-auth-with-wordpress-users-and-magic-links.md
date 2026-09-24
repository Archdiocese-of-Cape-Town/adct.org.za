# ADR 0007: Authentication with WordPress users, custom roles and magic links

- Status: Accepted
- Date: 2026-09-24

## Context
Parishes should manage their own events by entering their email and clicking a link, and stay logged in for a long time. Several archdiocese admins need different levels of access. Building a separate auth system is risky.

## Decision
- **Parish contacts are WordPress users** with a custom `parish_contact` role (no wp-admin access; they land in a front-end portal). A user is linked to one or more parishes through `adct_pi_parish_contacts.wp_user_id`.
- **Passwordless login**: enter your email → if it is a verified contact, a single-use login link (valid 30 minutes) is emailed → clicking it shows a "Log in" button (POST, to protect against link scanners) → `wp_set_auth_cookie($user, true)`. The response is the same whether or not the address exists, so addresses can't be discovered.
- **Long sessions**: `auth_cookie_expiration` is filtered for the `parish_contact` and `deanery_approver` roles only, default 365 days (configurable). Admin sessions keep WordPress defaults.
- **Capabilities** (custom, mapped to roles):
  - `adct_pi_manage_settings` – settings, AI/OCR, mailboxes (Administrator).
  - `adct_pi_manage_directory` – parishes, contacts, sources (Intake manager role).
  - `adct_pi_review` – review queue, approve/reject, edit any event (Intake reviewer role; editors by default).
  - `adct_pi_view_reports` – health and monitoring views.
  - `adct_pi_approve_deanery` – approve, reject or revert events for parishes in their assigned deaneries (**Deanery approver** role, for deans and their assistants; added by [ADR 0008](0008-approval-by-dean-or-archdiocese-reviewer.md)). Deanery approvers are front-end users like parish contacts: they log in with magic links, keep long sessions, and don't need wp-admin.
  - Parish contacts: can edit only events for their parishes; enforced on every request.
- A person can hold more than one of these (e.g. a dean who is also the parish priest is both a parish contact and a deanery approver).
- Contacts can be logged out everywhere (all sessions destroyed) from the admin screen.

## Consequences
- Reuses WordPress's secure session handling, password hashing (for admins) and user management.
- It creates many low-privilege users. They are clearly separated by role and hidden from normal user lists if needed.
- Login emails depend on reliable outbound email (SPF/DKIM).
