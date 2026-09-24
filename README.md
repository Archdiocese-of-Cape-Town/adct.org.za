# adct.org.za

## ADCT Parish Intake plugin

This repository now contains a lightweight WordPress plugin prototype for parish intake parsing on shared hosting.

### What it does

- Runs an offline-first parsing pipeline for parish communications.
- Normalizes source text, applies deterministic extraction, detects recurring events, scores confidence, and only then optionally calls an AI provider.
- Stores raw and extracted data separately so parses can be reviewed and reprocessed later.
- Adds a dedicated **Parish Intake** admin menu with separate **Settings** and **Manual parser** screens.
- Can generate a static HTML snapshot report from stored parse results.

### AI support

AI is disabled by default. If enabled in the admin screen, OpenRouter can be used as a low-confidence fallback only.

### Inbox parsing status

Automated inbox polling and mailbox connection settings are not part of this prototype yet. The current workflow is to configure AI fallback in **Parish Intake → Settings** and test messages manually in **Parish Intake → Manual parser**.

### Roadmap and design

The plan is to grow this prototype into an email-first intake loop: parishes email `events@adct.org.za`, confirm a preview of their events by email, and approved events appear on a filterable public events page and ICS feed. See [`docs/`](docs/README.md), especially:

- [Backlog and phases](docs/parish-intake-project-backlog.md)
- [Architecture](docs/architecture.md) and [data model](docs/data-model.md)
- [Decisions (ADRs)](docs/decisions/README.md)

### Project coordination

Use GitHub Issues plus a GitHub Project to coordinate work across sessions. Repository-side scaffolding for this lives in:

- [`.github/ISSUE_TEMPLATE/epic.yml`](.github/ISSUE_TEMPLATE/epic.yml)
- [`.github/ISSUE_TEMPLATE/work-item.yml`](.github/ISSUE_TEMPLATE/work-item.yml)
- [`docs/parish-intake-project-backlog.md`](docs/parish-intake-project-backlog.md)

### Validation

See the [development guide](docs/development.md) for setup (PHP runs in Docker), rules and the build order, and [`docs/testing.md`](docs/testing.md) for the test layers. To run all tests (PHPUnit and the prototype smoke test) through Docker:

```powershell
docker run --rm -v "${PWD}:/app" -w /app composer:2 install --no-interaction --no-progress
docker run --rm -v "${PWD}:/app" -w /app php:8.2-cli sh -c "vendor/bin/phpunit && php tests/parser_smoke_test.php"
```

GitHub Actions runs the same tests on PHP 8.2, 8.3 and 8.4 for every pull request.
