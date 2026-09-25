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
| Administrator | All five Parish Intake capabilities: settings, directory, review, reports and deanery approval. |
| Intake manager (`adct_pi_intake_manager`) | Settings, directory, review and reports. |
| Intake reviewer (`adct_pi_intake_reviewer`) | Review and reports. |
| Editor | Review and reports. This default is **provisional** and the project owner may change it. |
| Parish contact (`parish_contact`) | Read only; cannot use `wp-admin` or the admin bar. |
| Deanery approver (`deanery_approver`) | Deanery approval only; cannot use `wp-admin` or the admin bar. |

Assign a role from **Users → All Users → Edit** (or while adding a user). Role changes are additive: upgrades add missing Parish Intake capabilities and do not replace other capabilities already assigned to a role.

A deanery approver must also have an active assignment to one or more deaneries. Manage these assignments from **Parish Intake → Deaneries**; a role without an active assignment cannot approve deanery items, so do not attempt direct database edits. An archdiocese reviewer with the `adct_pi_review` capability can approve items from any deanery, including parishes with no deanery.

The parish portal, magic-link sign-in and its long-session policy are separate later work. The directory screens manage deanery assignments, but parish contacts and deanery approvers still do not gain a `wp-admin` interface.

## Manage deaneries and approvers

An Administrator or Intake manager with the `adct_pi_manage_directory` capability can open **Parish Intake → Deaneries**. Add, edit or deactivate a deanery; its dean, vice-dean and secretary names are display-only and do not grant approval access. The seeded deanery CSV can still be imported from **Parish Intake → Parishes**; it imports no approver accounts or email addresses.

Edit a deanery to assign several approvers. Select an existing WordPress user or create a new account, then set the approval email, label, per-item or daily-digest notifications, reminders and active status. The approval email is separate from the WordPress account email. A newly created account receives a random password and **no WordPress new-user notification or other email**. Existing users keep their other WordPress roles when `deanery_approver` is added. Deactivating an assignment preserves its row; the role is removed only when that user has no other active deanery assignments.

A parish in an inactive deanery, a parish with no deanery, or a parish whose deanery has no active approver is clearly marked **Reviewers only**. Its events remain reviewable by archdiocese reviewers; they never wait for a dean to be set up.

## Manage the parish directory

An Administrator or Intake manager with the `adct_pi_manage_directory` capability can open **Parish Intake → Parishes**. The list searches name, slug, area and suburb, and can be filtered by kind, deanery and status. Use **Add parish** or select a parish name to edit it. The form includes its deanery and parent parish, address, coordinates, website, phone, expected cadence, reminders, status and notes.

The map helper opens OpenStreetMap at saved coordinates, or searches the saved address in Google Maps when coordinates are blank. Copy the latitude and longitude from the map into the form; geocoding is not automatic.

To change several assignments at once, select parish rows on the current list page, choose a deanery (or **No deanery**) and select **Assign to selected parishes**. The list and each parish edit screen show **Reviewers only** whenever no active deanery approver can receive the item.

### Import parishes

1. If the directory has no deaneries yet, upload `deaneries.csv` first using **Import deaneries**. Its columns are `slug`, `name`, `dean`, `vice_dean` and `secretary`; the names are display-only and do not assign approvers.
2. Upload a parish CSV using **Import parishes**. The seed file `data/seed/parishes.csv` is accepted as-is. For private files, download the template first. Its columns are `slug`, `name`, `area`, `church`, `kind`, `is_mother_parish`, `parent_slug`, `deanery_slug`, `address`, `office_email`, `latitude`, `longitude`, `suburb`, `website`, `phone`, `expected_cadence_days`, `reminders_enabled`, `status` and `notes`.
3. Review the preview. Each row is marked **Create**, **Update**, **Unchanged** or **Error**. Rows match existing parishes by slug; re-importing updates matching records and never deletes a parish. Parent references resolve even if the parent row appears later in the CSV.
4. If any row has an error, no rows are imported. Fix the CSV and upload it again. Common errors include a missing name or slug, an unsupported kind, coordinates outside latitude `-90..90` or longitude `-180..180`, an unknown deanery or parent slug, a slug repeated in the file, or an invalid office email. Import the deaneries before using their slugs; a blank deanery or parent slug is allowed.
5. Select **Confirm import** only after the preview is correct. The uploaded content and parsed preview are held in a user-bound admin transient for 15 minutes; the plugin does not save the uploaded file to its own filesystem.

The v1 schema stores known parent relationships through `parent_slug`/`parent_parish_id`; it does not store the seed's separate `is_mother_parish` flag. That column is accepted for seed-file compatibility, but an export leaves it blank when no parent relationship is recorded.

An `office_email` in the CSV is added as a verified parish contact with a verification timestamp if the address has no existing links. If that address is already linked, its current trust is retained and shared with the new parish link. Existing contact details are left untouched. Use the import to add new official office addresses; contact management is available on each parish's edit screen and on the Senders screen.

**Export parishes CSV** downloads the current directory using the template's columns. **Download CSV template** provides the same header with no data rows. The export includes a verified office email when one is available; it does not include unknown, pending or blocked contacts. To protect spreadsheet users, cells beginning with `=`, `+`, `-`, `@`, a tab or a carriage return are prefixed with a single quote, except a value in the phone column that matches a phone-number pattern (for example, `+27 00 000 0000`). The importer removes that export-added prefix so an exported file can be imported again. Keep private contact details out of the public repository.

### Manage parish contacts and senders

1. Open **Parish Intake → Parishes**, select a parish, and use the **Contacts** section on its edit screen to add, edit or remove an email link. Each parish link can have its own display name, role label and reminder setting. Adding the same address to the same parish again updates that link rather than creating a duplicate.
2. Open **Parish Intake → Senders** to search by email address or contact name, filter by trust, see all linked parishes, and link an address to another parish. Trust is shared by the address across all of its parish links.
3. Use **Verify address** only after checking the contact with the parish. Verification fills in the sender's linked parish information and permits immediate changes to already-published events; every new event still needs approval by a dean or archdiocese reviewer.
4. **Block address** stops intake for that email address across every linked parish. An administrator can use **Unblock address** to return it to `unknown`; verify it again before treating it as trusted. A blocked address cannot lose its final parish link until an administrator explicitly unblocks it, so removing one of several links does not change trust for the remaining links.

## Configure parser safeguards

An Administrator or Intake manager with settings access can open **Parish Intake → Settings** and edit the non-event section phrases. Enter one heading or leading phrase per line in each category. Matching ignores case and punctuation; a recognized section is skipped through the next heading. Weekly Mass-times tables with weekday/time rows that identify Mass or Service are also skipped automatically.

These safeguards cover Mass times and intentions, sick lists, deceased, anniversaries, raffle winners, collections/finances, banking details and readings. Keep recognizable phrases in each category so these sections stay out of event candidates and AI enrichment. A blank category uses its built-in defaults. Select **Reset section keywords to defaults** to restore all built-in lists; **Save settings** saves the current lists.

To investigate a possible false skip, paste the source into **Parish Intake → Manual parser**. The latest outcome reports each skipped block's zero-based `block_index` and category in its `reason`; use the index to find the section in the raw text you supplied. Skipped text is deliberately absent from the parse outcome. A full viewer for stored inbound messages is not part of the current admin screens.

## Check database installation and upgrade (staging)

Do this on a staging site with a recent database backup; do not change schema options on the live site.

1. On a fresh staging install, activate the plugin and use the site's database manager to confirm `adct_pi_db_version` is `1` and that the 15 `adct_pi_*` tables in [the data model](data-model.md) exist.
2. On a staging copy of a prototype installation, update/activate the new plugin and visit a WordPress admin page to run the upgrade check. Confirm the option is `1`, the new tables exist, and no database-upgrade error notice is shown.
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

To start a registered job manually, an Administrator or Intake manager can open **Parish Intake → Scheduled jobs** and select **Run now** beside it. The action requires the settings-management capability, is protected by a nonce, and uses the same time limit, item limit, lock, and checkpoint as cron. A run that reaches a budget saves its checkpoint so the next run can continue. The currently registered **Framework heartbeat (no work configured)** only checks the framework; it does not read parish email, process events, or send messages. Mailbox and queue jobs will appear there when those features are implemented.

## Uninstall and data retention

Uninstalling the plugin removes its custom roles and Parish Intake capabilities from the built-in Administrator and Editor roles. It does **not** drop Parish Intake tables or delete plugin data. Data deletion requires a separate owner decision.
