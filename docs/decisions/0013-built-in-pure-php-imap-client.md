# ADR 0013: Built-in pure-PHP IMAP client

- Status: Accepted
- Date: 2026-09-25

## Context
ADR 0002 chose IMAP polling behind `MailboxInterface` but left the client selection to a spike. The xneelo host has no `ext-imap`; production is PHP 8.2 and CI also targets 8.3 and 8.4. The existing MIME parser already handles RFC 822 content, so the IMAP client only needs to retrieve bounded raw messages and mailbox metadata.

The spike compared current package metadata and resolved production dependency trees on Composer's PHP 8.2 platform. The archive measurements use Strauss 0.30.0 and the same exclusion/prefix settings as the release build. They zip only the prefixed Composer vendor directory, so the existing plugin source is common to each result.

| Candidate | PHP constraint; extensions; license | Additional Composer packages (total) | Strauss-prefixed vendor zip |
|---|---|---:|---:|
| Current MIME-parser baseline | PHP 8.2+; MIT runtime packages | — (15) | 1,025,835 bytes |
| `webklex/php-imap` 6.2.0 | `^8.0.2` (includes 8.2–8.4); requires common extensions including `ext-zip`, not `ext-imap`; MIT | 22 (37), including `illuminate/pagination`, `illuminate/support`, Carbon and Symfony packages | 3,164,279 bytes (+2,138,444) |
| `directorytree/imapengine` 1.25.6 | `^8.1` (includes 8.2–8.4); no `ext-imap`; MIT | 21 (36), including `illuminate/collections`, Symfony MIME, Carbon and email validation; reuses the existing MIME parser | 2,641,781 bytes (+1,615,946) |
| Built-in client | PHP 8.2+; PHP streams; no added extensions or packages; no third-party license | 0 (15) | 1,025,835 bytes (+0 vendor bytes) |

Package metadata: [Webklex on Packagist](https://packagist.org/packages/webklex/php-imap) and its [6.2.0 Composer manifest](https://github.com/Webklex/php-imap/blob/6.2.0/composer.json); [ImapEngine on Packagist](https://packagist.org/packages/directorytree/imapengine) and its [1.25.6 Composer manifest](https://github.com/DirectoryTree/ImapEngine/blob/v1.25.6/composer.json). The PHP constraints include the project's 8.2–8.4 matrix; the comparison does not claim either library's upstream CI runs that exact matrix.

## Decision
- Implement the client in `src/Core` with PHP's standard stream/socket APIs. Keep the mailbox API provider-neutral and isolate wire I/O behind a small `TransportInterface` for scripted protocol tests.
- Support only the commands needed by intake: LOGIN, CAPABILITY, SELECT, UID SEARCH, UID FETCH, LIST, CREATE, mark-seen, MOVE or COPY/STORE/EXPUNGE, STARTTLS, and LOGOUT. The MIME parser remains responsible for interpreting fetched RFC 822 bytes.
- Use TLS peer and hostname verification by default. Plain connections and disabled verification require an explicit test-only opt-in. Read size metadata before requesting a message body and refuse to download messages over the configurable 30 MiB default.
- Use `UID MOVE` when advertised. Otherwise copy, mark the source `\Deleted`, and use `UID EXPUNGE` when UIDPLUS is available; legacy servers without UIDPLUS require mailbox-wide `EXPUNGE`.
- Keep `ext-imap` and third-party IMAP packages out of Composer and the release zip.

## Consequences
- No new runtime dependency or prefixed-library payload is added, and the client works on the supported PHP versions without `ext-imap`.
- We own the supported subset of IMAP protocol handling. Unit tests use a scripted transport and the separate GreenMail job verifies actual SMTP delivery and IMAP operations.
- On servers without UIDPLUS, `EXPUNGE` may also remove other messages already marked `\Deleted` in the selected folder. OAuth2/XOAUTH2 and broader IMAP features remain out of scope.
