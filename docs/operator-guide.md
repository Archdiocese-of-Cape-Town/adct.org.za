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

To start a registered job manually, open **Parish Intake → Scheduled jobs** and select **Run now** beside it. The action is limited to administrators, protected by a nonce, and uses the same time limit, item limit, lock, and checkpoint as cron. A run that reaches a budget saves its checkpoint so the next run can continue. The currently registered **Framework heartbeat (no work configured)** only checks the framework; it does not read parish email, process events, or send messages. Mailbox and queue jobs will appear there when those features are implemented.
