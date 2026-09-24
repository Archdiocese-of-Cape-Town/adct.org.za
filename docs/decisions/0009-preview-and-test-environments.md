# ADR 0009: Preview and test environments

- Status: Accepted
- Date: 2026-09-24

## Context
The team doesn't want to maintain a permanent staging site. Reviewers, who are often non-technical, still need to click through changes before they reach the live site. Several free WordPress test services can load a plugin from a public GitHub repository, and this repository is public.

The plugin bundles namespace-prefixed Composer dependencies (Strauss/PHP-Scoper), so the raw repository checkout isn't a runnable plugin. A build step has to produce the zip.

## Decision
1. **One build artifact.** CI builds the plugin zip (stable folder `adct-parish-intake/`). The same zip is used for previews, test sites and production.
2. **WordPress Playground PR previews** are the default review tool. They use the official [`WordPress/action-wp-playground-pr-preview`](https://github.com/WordPress/action-wp-playground-pr-preview) GitHub Action in its **two-workflow build path**:
   - `pr-preview-build.yml` runs `composer install --no-dev` plus the prefixing and zip steps, with read-only permissions.
   - `pr-preview-publish.yml` (a `workflow_run` workflow, which must be on `main`) uploads the zip to a `ci-artifacts` prerelease and adds a **"Preview in WordPress Playground"** button to the PR.
   - A custom blueprint (`.github/playground/blueprint.json`) installs the zip, activates it, and loads sample parishes, deaneries, users and messages. Reviewers can then try the review queue, approval pages, events page and ICS feed.
   - Limitation: Playground can't open IMAP sockets. Mail intake is shown with sample messages and the manual "paste an email" tool.
3. **InstaWP / TasteWP** are used for real mail tests. Install the CI-built zip by URL, connect a **test mailbox**, and turn on **Test mode**, which sends outbound mail only to allow-listed addresses. Sites expire, and only throwaway test credentials are used there. InstaWP's own GitHub deployment is optional; running Composer on its side is a paid feature, so the zip is simpler.
4. **No permanent staging.** Before the first launch, and optionally before big releases, a **temporary staging instance on xneelo** checks the real cron, time limit and mail sending, using the release checklist in [testing](../testing.md). It can be removed afterwards.
5. The **production launch** starts with a few pilot parishes. Every new event needs approval ([ADR 0008](0008-approval-by-dean-or-archdiocese-reviewer.md)), so a mistake in the pilot can't publish anything unreviewed.

## Consequences
- Every PR gets automated tests and a one-click preview without anyone running servers.
- The publish workflow only takes effect once it is merged to `main` (GitHub reads `workflow_run` workflows from the default branch).
- Previews use public release URLs, so sample data must never contain real personal information.
- Real xneelo behaviour is only checked at the temporary staging step and during the pilot, so hosting problems may show up late. Job budgets and locks are designed conservatively to reduce this risk ([hosting environment](../hosting-environment.md)).
