# ADCT Parish Intake operator guide

## Install and upgrade

You need a WordPress administrator account that is allowed to install plugins. Use the `adct-parish-intake.zip` file attached to the GitHub Release. Do not download GitHub's source-code archive or unzip the plugin package yourself.

### First installation

1. Sign in to the WordPress admin area.
2. Open **Plugins → Add New** (sometimes labelled **Add New Plugin**) and select **Upload Plugin**.
3. Choose `adct-parish-intake.zip`, then select **Install Now**.
4. When WordPress says the installation is complete, select **Activate Plugin**.
5. Return to **Plugins → Installed Plugins** and check that **ADCT Parish Intake** is marked **Active**.
6. Open **Parish Intake → Manual parser** and check that the page loads.

### Upgrade an existing installation

1. Make sure there is a recent backup of the website before upgrading.
2. Download the new `adct-parish-intake.zip` from its GitHub Release.
3. In WordPress, open **Plugins → Add New** (sometimes labelled **Add New Plugin**) and select **Upload Plugin**. Choose the zip and select **Install Now**.
4. If WordPress asks what to do with the existing plugin, select **Replace current with uploaded**.
5. Return to **Plugins → Installed Plugins** and check that **ADCT Parish Intake** is marked **Active**. If WordPress asks you to activate it, select **Activate**.
6. Open **Parish Intake → Manual parser** and check that the page loads.

The upload replaces the plugin files; it is not an instruction to delete the plugin from the Installed Plugins page. If WordPress reports an error or the plugin is no longer active, stop and contact the person who looks after the website rather than deleting files manually.

## Roles and access

Parish Intake adds these WordPress roles and capabilities:

| Role | Parish Intake access |
|---|---|
| Administrator | All five Parish Intake capabilities: settings, directory, review, reports and deanery approval; full event management. |
| Intake manager (`adct_pi_intake_manager`) | Settings, directory, review, reports and event management. |
| Intake reviewer (`adct_pi_intake_reviewer`) | Review, reports and event management. |
| Editor | Review, reports and event management. The Parish Intake review access is **provisional** and the project owner may change it. |
| Parish contact (`parish_contact`) | Read only; cannot use `wp-admin` or the admin bar. |
| Deanery approver (`deanery_approver`) | Deanery approval only; cannot use `wp-admin` or the admin bar. |

Assign a role from **Users → All Users → Edit** (or while adding a user). Role changes are additive: upgrades add missing Parish Intake capabilities and do not replace other capabilities already assigned to a role.

A deanery approver must also have an active assignment to one or more deaneries. Manage these assignments from **Parish Intake → Deaneries**; a role without an active assignment cannot approve deanery items, so do not attempt direct database edits. An archdiocese reviewer with the `adct_pi_review` capability can approve items from any deanery, including parishes with no deanery.

The parish portal, magic-link sign-in and its long-session policy are separate later work. The directory screens manage deanery assignments, but parish contacts and deanery approvers still do not gain a `wp-admin` interface.

### Create and edit events

Users with event-management access can open **Events → Add New**. Enter the public title and description in the standard WordPress editor, then complete the **Event details** box. A parish may be selected, or left blank for an archdiocese-wide event. Venue choices are filtered to the selected parish. Start and end values use the site's local timezone (`Africa/Johannesburg`); an end is optional but cannot be earlier than the start. For an all-day event, the saved times are normalized to local midnight and the selected end date is inclusive.

The recurrence builder supports no recurrence, weekly on a weekday, monthly on the first/second/third/fourth/last weekday, monthly on day 1–31, or a custom RRULE. The custom rule accepts `FREQ`, `INTERVAL`, `COUNT`, `UNTIL`, `BYDAY`, `BYMONTHDAY`, `BYMONTH` and `BYSETPOS`; `COUNT` and `UNTIL` cannot be used together. Enter one local date and time per line in **Excluded dates** or **Additional dates**. Saving a published event immediately replaces its occurrence rows; REST writes do the same. Draft, pending and private posts have no occurrence rows.

Occurrences use the inclusive site-local date window from today through the same date one year later. A leap-day window anniversary ends on February 28; a yearly February 29 event has no instance in non-leap years. UNTIL includes its boundary. DTSTART is retained and counts as the first RRULE occurrence even if it does not match its filters. COUNT is applied before exclusions; additional dates do not consume COUNT and can be later than UNTIL; excluded dates remove matching RRULE or additional-date starts. Negative BYDAY ordinals count backward from the end of the month or year: `-1SU` is the last Sunday and `-1FR` the last Friday. A missing fifth weekday in a month is skipped, not moved into the next month. These are provisional, reversible expansion defaults. The daily **Occurrence expansion** job refreshes the window in bounded batches and resumes from a saved checkpoint; use **Parish Intake → Scheduled jobs → Run now** to retry it manually.

The status flag is separate from WordPress's Draft/Published post status: it records Scheduled, Cancelled or Postponed. Publishing an event makes the post public; changing it away from Published or deleting it removes its occurrence rows. Cancelled occurrences remain stored and are marked cancelled; postponed occurrences remain stored and are not marked cancelled, while the event's Postponed status remains available for display. If occurrence rebuilding fails, the previous rows are preserved; the REST save reports an error, or the event editor shows an admin notice with a retry/contact instruction. A failed REST create has still saved the event and its error includes the event ID; update that saved event instead of repeating the create request. Contact name, email and phone are private event metadata, available only to users who can edit the event and never exposed by the public REST API. The starter event types (Social, Spiritual, Formation, Liturgy/Mass, Youth, Outreach, Fundraising, Meeting and Other) are **provisional**. Administrators and Editors can manage terms under **Events → Event Types**; event editors can assign existing terms. If several types are assigned, occurrence filtering uses the lowest term ID until a multi-type model is designed.

## Manage deaneries and approvers

An Administrator or Intake manager with the `adct_pi_manage_directory` capability can open **Parish Intake → Deaneries**. Add, edit or deactivate a deanery; its dean, vice-dean and secretary names are display-only and do not grant approval access. The seeded deanery CSV can still be imported from **Parish Intake → Parishes**; it imports no approver accounts or email addresses.

Edit a deanery to assign several approvers. Select an existing WordPress user or create a new account, then set the approval email, label, per-item or daily-digest notifications, reminders and active status. The approval email is separate from the WordPress account email. A newly created account receives a random password and **no WordPress new-user notification or other email**. Existing users keep their other WordPress roles when `deanery_approver` is added. Deactivating an assignment preserves its row; the role is removed only when that user has no other active deanery assignments.

A parish in an inactive deanery, a parish with no deanery, or a parish whose deanery has no active approver is clearly marked **Reviewers only**. Its events remain reviewable by archdiocese reviewers; they never wait for a dean to be set up.

## Manage the parish directory

An Administrator or Intake manager with the `adct_pi_manage_directory` capability can open **Parish Intake → Parishes**. The list searches name, slug, area and suburb, and can be filtered by kind, deanery and status. Use **Add parish** or select a parish name to edit it. The form includes its deanery and parent parish, address, coordinates, website, phone, expected cadence, reminders, status and notes.

The map helper opens OpenStreetMap at saved coordinates, or searches the saved address in Google Maps when coordinates are blank. Copy the latitude and longitude from the map into the form; geocoding is not automatic.

To change several assignments at once, select parish rows on the current list page, choose a deanery (or **No deanery**) and select **Assign to selected parishes**. The list and each parish edit screen show **Reviewers only** whenever no active deanery approver can receive the item.

### Manage parish venues

On a parish's edit screen, open the **Venues** tab to add, edit or deactivate a church, hall or other named place. Each venue has a name, optional aliases (one per line or comma-separated), address, suburb and optional latitude/longitude. Coordinates are range-checked. Mark one active venue as the default; selecting it clears the default flag from the parish's other venues. Deactivating the default promotes another active venue, and the final active venue cannot be deactivated.

Venues generated by an import are **provisional**. Check their names, addresses and coordinates before relying on them for event locations. Parish and venue coordinates are entered manually; the plugin does not geocode addresses.

### Import parishes

1. If the directory has no deaneries yet, upload `deaneries.csv` first using **Import deaneries**. Its columns are `slug`, `name`, `dean`, `vice_dean` and `secretary`; the names are display-only and do not assign approvers.
2. Upload a parish CSV using **Import parishes**. The seed file `data/seed/parishes.csv` is accepted as-is. For private files, download the template first. Its columns are `slug`, `name`, `area`, `church`, `kind`, `is_mother_parish`, `parent_slug`, `deanery_slug`, `address`, `office_email`, `latitude`, `longitude`, `suburb`, `website`, `phone`, `expected_cadence_days`, `reminders_enabled`, `status` and `notes`.
3. Review the preview. Each row is marked **Create**, **Update**, **Unchanged** or **Error**. Rows match existing parishes by slug; re-importing updates matching records and never deletes a parish. Parent references resolve even if the parent row appears later in the CSV.
4. If any row has an error, no rows are imported. Fix the CSV and upload it again. Common errors include a missing name or slug, an unsupported kind, coordinates outside latitude `-90..90` or longitude `-180..180`, an unknown deanery or parent slug, a slug repeated in the file, or an invalid office email. Import the deaneries before using their slugs; a blank deanery or parent slug is allowed.
5. Select **Confirm import** only after the preview is correct. The uploaded content and parsed preview are held in a user-bound admin transient for 15 minutes; the plugin does not save the uploaded file to its own filesystem.
6. After a parish import, each parish with no venue receives a provisional default named from its `church` value, or its parish name when `church` is blank. Each outstation or mass centre with a `parent_slug` also creates one venue on its parent parish, with the child parish's name as an alias. The child parish row is unchanged, and re-imports do not duplicate linked venues. On existing installations, use **Set up venue records → Create missing venue records** on the Parishes page; it is safe to repeat.

The parish directory stores known parent relationships through `parent_slug`/`parent_parish_id`; it does not store the seed's separate `is_mother_parish` flag. That column is accepted for seed-file compatibility, but an export leaves it blank when no parent relationship is recorded.

An `office_email` in the CSV is added as a verified parish contact with a verification timestamp if the address has no existing links. If that address is already linked, its current trust is retained and shared with the new parish link. Existing contact details are left untouched. Use the import to add new official office addresses; contact management is available on each parish's edit screen and on the Senders screen.

For a parish with no official source, the import also registers that office email as its official `email` source. Re-importing is safe: it does not duplicate the source or replace a different official source. This source seeding is provisional.

**Export parishes CSV** downloads the current directory using the template's columns. **Download CSV template** provides the same header with no data rows. The export includes a verified office email when one is available; it does not include unknown, pending or blocked contacts. To protect spreadsheet users, cells beginning with `=`, `+`, `-`, `@`, a tab or a carriage return are prefixed with a single quote, except a value in the phone column that matches a phone-number pattern (for example, `+27 00 000 0000`). The importer removes that export-added prefix so an exported file can be imported again. Keep private contact details out of the public repository.

### Manage parish contacts and senders

1. Open **Parish Intake → Parishes**, select a parish, and use the **Contacts** section on its edit screen to add, edit or remove an email link. Each parish link can have its own display name, role label and reminder setting. Adding the same address to the same parish again updates that link rather than creating a duplicate.
2. Open **Parish Intake → Senders** to search by email address or contact name, filter by trust, see all linked parishes, and link an address to another parish. Trust is shared by the address across all of its parish links.
3. Use **Verify address** only after checking the contact with the parish. Verification fills in the sender's linked parish information and permits immediate changes to already-published events; every new event still needs approval by a dean or archdiocese reviewer.
4. **Block address** stops intake for that email address across every linked parish. An administrator can use **Unblock address** to return it to `unknown`; verify it again before treating it as trusted. A blocked address cannot lose its final parish link until an administrator explicitly unblocks it, so removing one of several links does not change trust for the remaining links.

### Manage sources

An Administrator or Intake manager with `adct_pi_manage_directory` can open **Parish Intake → Sources** to see sources across the archdiocese. Filter by parish (including archdiocese-wide), type, role or status, and review the last check, last success, last item, consecutive failures and last error. Use **Add archdiocese-wide source** to create or edit a source that is not tied to a parish. Parish-bound sources are edited from that parish's **Sources** tab; the global list links back to the parish tab.

On a parish's Sources tab, add or edit a source and select its type, identifier, role, status and polling interval. Email identifiers are normalized to lowercase; ICS, PDF, Facebook Page, RSS and web-page identifiers must be HTTP(S) URLs; forwarded-WhatsApp and manual sources use a short label. A parish can have no official source, but it can have at most one: selecting another as **Official** demotes the previous one to **Monitored** in the same transaction. Archdiocese-wide sources can have multiple Official entries without a uniqueness limit (provisional).

Pollable sources default to 1,440 minutes (24 hours) and cannot be set below 10 minutes; both values are provisional. Manual sources are not polled and have no poll interval. Health fields are read-only. The mailbox job is scheduled every 10 minutes but opens IMAP only when an active source's `last_checked_at` is absent or its configured interval has elapsed; the interval is measured from `last_checked_at`. An incomplete UID scan resumes promptly on the next run instead of waiting for the full interval. After a failed poll, a provisional retry backoff starts at 10 minutes, doubles with each consecutive failure and is capped at 6 hours; it is also measured from `last_checked_at` and takes precedence over the source interval. The mailbox poller marks an active email source **Unreliable** after five consecutive failures (provisional); **Paused** and **Disabled** sources are not changed by failures. Only active email sources with active mailbox settings and a configured password are polled. An Unreliable source is not polled again until an operator investigates the connection and changes its status back to **Active**. A successful poll clears the failure count and last error. Last errors are technical diagnostics only; do not put message contents or personal information in them.

## Outbound email and hourly cap

All plugin email is stored in `adct_pi_mail_queue` and delivered one recipient at a time through WordPress `wp_mail()`. The site-wide FluentSMTP configuration routes it through xneelo's authenticated SMTP; Parish Intake stores no SMTP credentials. Outbound mail has no BCC recipients or attachments.

The queue sends login links and confirmations first, approver and change notices second, then reminders and digests. It counts successfully sent recipients in the preceding 60 minutes and reserves capacity for active sends so overlapping workers cannot exceed the cap. The default is **100 per hour**, leaving room for the rest of adct.org.za under xneelo's shared 500-recipient limit.

There is no admin setting for the cap yet. A technical operator can set `ADCT_PI_MAIL_HOURLY_CAP` in `wp-config.php` to an integer from `1` to `500`; if it is absent, the cap stays at 100. **Keep 100 unless the site owner has reviewed other site mail volume. A value of 500 can consume the whole shared allowance.** An invalid value is logged and safely falls back to 100.

```php
define('ADCT_PI_MAIL_HOURLY_CAP', 100);
```

Failed deliveries retry with exponential backoff and become terminally failed after five attempts. A thrown delivery exception or interrupted send with unknown outcome conservatively holds its cap reservation for 60 minutes before retrying; in the rare case that SMTP accepted it just before the process stopped, that retry may deliver a duplicate. The status API exposes pending count, oldest pending age, successful sends in the preceding hour and terminal failure count for the planned health dashboard, but there is not yet an operator queue screen.

`group_key` is an idempotency key for one fully composed message to one recipient. Repeating the same key and payload does not create another row; changing content under the same recipient/key is an error, not a silent merge or drop. Compose a complete approver digest before enqueueing it, then use a recipient-specific key.

Use **Parish Intake → Outbound email** to enable Test mode before sending mail from an InstaWP, TasteWP or temporary staging site. Test mode is **off by default** for production. When it is on, only the exact addresses and exact domains in the allow-list can receive Parish Intake queue mail. Enter one entry per line: a full email address such as `test-inbox@example.test`, or an exact domain prefixed with `@` such as `@example.test`. A domain does not include subdomains. This setting applies only to Parish Intake's queue and never forwards, rewrites or blocks mail from other WordPress plugins.

The admin banner remains visible while Test mode is on. A message to a non-allow-listed recipient is recorded as **suppressed** and never reaches `wp_mail()`. Messages already queued are checked against the current settings again before delivery, so enabling Test mode or changing the allow-list also protects rows waiting in the queue. A message already recorded as suppressed is not automatically requeued if the allow-list changes; enqueue it again only if it should be sent. An empty allow-list while Test mode is on, or any invalid setting, blocks all Parish Intake queue delivery and displays a clear admin error. The Outbound email screen shows the latest suppressed messages with escaped, bounded subject/body previews; only users with settings access can open it.

## Configure parser safeguards

An Administrator or Intake manager with settings access can open **Parish Intake → Settings** and edit the non-event section phrases. Enter one heading or leading phrase per line in each category. Matching ignores case and punctuation. A standalone category phrase or a match formatted as a Markdown/underlined, all-caps or colon-terminated heading skips through the next heading, even when that section contains dates or times. A phrase at the start of running text skips only its block when there is no explicit date plus time or event noun. If that event signal is present, the candidate is kept with a text-free `section_keyword_overridden: <category>` note and its confidence is reduced by 0.1 for closer review. Weekly Mass-times tables with weekday/time rows that identify Mass or Service are also skipped automatically.

These safeguards cover Mass times and intentions, sick lists, deceased, anniversaries, raffle winners, collections/finances, banking details and readings. Keep recognizable phrases in each category so these sections stay out of event candidates and AI enrichment. A blank category uses its built-in defaults. Select **Reset section keywords to defaults** to restore all built-in lists; **Save settings** saves the current lists.

To investigate a possible false skip or keyword override, paste the source into **Parish Intake → Manual parser**. The latest outcome reports each skipped block's zero-based `block_index` and category in its `reason`; use the index to find the section in the raw text you supplied. The `section_keyword_overridden` note identifies retained candidates that need a closer look. Skipped text is deliberately absent from the parse outcome. The Mailboxes screen shows recent automated/list flags and structured SPF/DKIM/DMARC summaries, but does not show sender addresses, subjects, raw headers or message bodies. A full viewer for stored inbound messages is not part of the current admin screens.

If an event after a Mass-intentions or prayer list is missed, give the events their own heading (e.g. EVENTS).

## Configure API keys and mailbox passwords

### Connect the events mailbox on xneelo

An Administrator or Intake manager with settings access can open **Parish Intake → Mailboxes** and select **Add mailbox**. For the Archdiocese events mailbox, use:

| Field | Value |
|---|---|
| Label | `Archdiocese events` |
| IMAP host | `<IMAP hostname from xneelo konsoleH>` (placeholder; use the exact server hostname shown for the mailbox, not a custom alias) |
| Port | `993` |
| Encryption | `SSL/TLS` |
| Username / email address | `events@adct.org.za` |
| Inbox folder | `INBOX` |
| Processed folder | `Processed` |
| Maximum message size | `30` MB |

TLS certificate and hostname verification is always on for production connections. If **Test connection** reports a secure-connection or certificate error, follow this instruction: **Use the mail server name shown on the certificate (for xneelo, the server hostname from your hosting control panel) instead of a custom alias.** Do not change the hostname to an unverified alias.

Enter the mailbox password in the write-only field, or use a password constant described below. The Mailboxes screen never shows a saved password. When a password is saved in the database, leave the field blank to keep it; select **Remove saved password** to delete it. Database-stored passwords are not encrypted by this plugin.

Select **Test connection** on the mailbox row to sign in, count unseen messages in the inbox and check that the Processed folder exists. If the folder is missing, select **Create processed folder** in the test result. The check does not download message bodies or start polling. An active mailbox linked to an active email source, with a configured password, is polled by the scheduled `poll_mailboxes` job; open **Parish Intake → Scheduled jobs** and select **Run now** to start a bounded poll immediately. Each run stops at 60 seconds or 100 mailbox steps and resumes from the saved UID checkpoint. New messages are stored as raw `.eml` files with eligible attachments, de-duplicated, screened for automated/list signals, then moved to Processed. Declared automation (such as `Auto-Submitted: auto-replied` or a delivery-status report) is distinguished from likely signals (such as list headers, a null `Return-Path` or a no-reply-style address), but both set the no-confirmation flag. SPF/DKIM/DMARC verdicts are saved as structured data; polling does not parse events, publish or modify them, or send email.

### Review authentication summaries

The Mailboxes screen labels authentication verdicts from an unconfigured or unknown authserv-id as **unverified claims**. By default no authserv-id is trusted, so an attacker-supplied `Authentication-Results` header cannot establish a verified pass or make a sender trusted.

Only configure `ADCT_PI_TRUSTED_AUTHSERV_IDS` after confirming that the receiving mail server removes or safely rewrites sender-supplied `Authentication-Results` headers before stamping its own result. Use the exact authserv-id assigned by that trusted receiving server; a matching header value on its own is not proof that the server generated it. The default is an empty list:

```php
define('ADCT_PI_TRUSTED_AUTHSERV_IDS', []);
```

If the MTA behavior has been verified, an operator may configure its exact authserv-id (example only):

```php
define('ADCT_PI_TRUSTED_AUTHSERV_IDS', ['mx.example.test']);
```

The screen shows verdicts and their configured trust status, not raw headers or message contents. A reported DMARC fail is visibly flagged for review; the Core `InboundMailPolicy::canApplyInstantChange()` also blocks instant changes for that message from a verified contact. The approval route will consume this policy in #71. This screening stage does not send confirmation or approval email.

Raw mail and accepted attachments are kept in a private uploads subdirectory protected by `.htaccess` deny rules and an `index.php` guard. Attachment storage is provisional: PDF, JPEG, PNG, WebP, HEIC and HEIF files are accepted only when the declared MIME type matches the file signature and the file is no larger than 15 MiB. Other attachments are not stored; their metadata and skip reason appear as a warning on the Mailboxes screen. Messages over the mailbox's configured size limit are recorded as skipped, moved to a `Too large` folder created by the poller, and listed with an administrator-visible warning. A failed message remains available for a later retry; check the mailbox health and Scheduled jobs screens for errors.

### Configure keys and password constants

The OpenRouter API key can be stored in the WordPress options database or supplied as `ADCT_PI_AI_API_KEY`. A non-empty constant takes precedence. On **Parish Intake → Settings**, a configured constant is shown only as **Set in wp-config.php**; a saved database key is never displayed, and leaving its password field blank keeps it. Select **Remove saved key** to delete a database key, including one overridden by a constant. Mailbox passwords are managed from **Parish Intake → Mailboxes**. OCR secrets are reserved for a later settings screen. Future global secrets follow the `ADCT_PI_<PURPOSE>` constant and `adct_parish_intake_<purpose>` option naming pattern and must be added to the secrets registry.

To keep secrets out of the database, edit `wp-config.php` and add the needed definitions above the line that says `That's all, stop editing!`:

```php
define('ADCT_PI_AI_API_KEY', 'replace-with-your-openrouter-api-key');
define('ADCT_PI_IMAP_PASSWORD', 'replace-with-the-events-mailbox-password');
define('ADCT_PI_OCR_API_KEY', 'replace-with-your-ocr-api-key');
```

The values above are placeholders; replace them with the site's credentials and do not commit those values to a repository. The default `ADCT_PI_IMAP_PASSWORD` constant is used for mailboxes without a per-mailbox constant. After saving a mailbox, its form shows the exact per-mailbox constant name; it uses the stable mailbox ID (for example, mailbox ID `12` uses `ADCT_PI_IMAP_PASSWORD_MAILBOX_12`). A per-mailbox constant takes precedence over the default constant, and either non-empty constant takes precedence over a stored database password. Add the constant to `wp-config.php` above the stop-editing line; do not store its value in the repository.

## Check database installation and upgrade (staging)

Do this on a staging site with a recent database backup; do not change schema options on the live site.

1. On a fresh staging install, activate the plugin and use the site's database manager to confirm `adct_pi_db_version` is `4` and that the 16 `adct_pi_*` tables in [the data model](data-model.md) exist. Confirm `adct_pi_occurrences.parish_id` is nullable; `adct_pi_venues` has the aliases, coordinates, default, status and source-parish columns; `adct_pi_sources` has its registry and health columns; and `adct_pi_mailboxes` has its source link and connection settings.
2. On a staging copy of a v3 installation, update/activate the new plugin and visit a WordPress admin page to run the v3-to-v4 upgrade. Confirm the option is `4`, `adct_pi_occurrences.parish_id` is nullable, the venue/source/mailbox tables exist, and no database-upgrade error notice is shown.
3. Confirm `wp_adct_parish_intake_items` and its row count are unchanged, then open **Parish Intake → Manual parser** and verify that recent stored parses still appear.

## Keep scheduled jobs running

WP-Cron must remain enabled. Do not add `DISABLE_WP_CRON` to `wp-config.php`; normal site visits trigger due jobs.

1. Sign in to xneelo konsoleH, open the site's hosting package, and go to its **Cron Jobs** section.
2. Add one job and set its frequency to **every 2 hours** (the maximum supported by this hosting account).
3. Have it make an HTTPS GET request to:

```text
https://<site>/wp-cron.php?doing_wp_cron
```

If the cron screen accepts a command, use either:

```sh
curl -fsS --max-time 60 'https://<site>/wp-cron.php?doing_wp_cron' >/dev/null
```

or:

```sh
wget -q -O - 'https://<site>/wp-cron.php?doing_wp_cron' >/dev/null
```

Replace `<site>` with the site's hostname. This uses one of the account's ten cron jobs; do not create a separate xneelo cron entry for each plugin job. WP-Cron dispatches the registered jobs, and each job decides whether it is due.

For more timely triggers, optionally create a free cron-job.org job that sends a GET request to the same URL every **5–10 minutes**. No secret or WordPress login is needed for this public `wp-cron.php` backstop. Check the service's execution history for successful HTTP responses.

To start a registered job manually, an Administrator or Intake manager can open **Parish Intake → Scheduled jobs** and select **Run now** beside it. The action requires the settings-management capability, is protected by a nonce, and uses the same time limit, item limit, lock, and checkpoint as cron. A run that reaches a budget saves its checkpoint so the next run can continue. **Occurrence expansion** is due daily and refreshes only Published events. **Framework heartbeat** only checks the framework; it does not read parish email or send messages.

## Uninstall and data retention

Uninstalling the plugin removes its custom roles and Parish Intake capabilities from the built-in Administrator and Editor roles. It does **not** drop Parish Intake tables or delete plugin data. Data deletion requires a separate owner decision.
