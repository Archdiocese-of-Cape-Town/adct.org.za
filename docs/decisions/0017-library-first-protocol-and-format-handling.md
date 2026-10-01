# ADR 0017: Library-first protocol and format handling

- Status: Accepted
- Date: 2026-09-30
- Supersedes: 0013; the HTML tokenizer choice in 0012

## Context
The project already uses maintained pure-PHP libraries for MIME parsing (ADRs 0012 and 0014) and PDF text extraction (ADR 0015), but ADR 0013 selected a handwritten IMAP protocol client to avoid additional dependencies. ADR 0012 explicitly selected a custom HTML tag tokenizer. Both choices make the project responsible for handling edge cases in widely implemented formats. The shared host has no `ext-imap`, and `ext-dom` cannot be assumed; release dependencies must work with PHP 8.2 and be namespace-prefixed.

## Decision
- Prefer a maintained, appropriately licensed library over handwritten implementations of standard protocols, parsers, serializers and other common utilities. Evaluate compatibility with PHP 8.2–8.4, available extensions, security maintenance, license, resource limits, package size and Strauss prefixing. Dependency count or zip size alone is not sufficient reason to implement a protocol or parser ourselves.
- Replace the built-in IMAP protocol implementation behind `MailboxInterface` with a suitable pure-PHP library, rather than extending the handwritten client for new functionality. Do not use `ext-imap`. Retain the existing TLS verification, bounded fetch, UID/checkpoint, move/retention safety and GreenMail behavior as requirements for the replacement. Evaluate candidate libraries against these requirements before choosing one; this ADR does not select a package or assert that the current code has been migrated.
- Replace the custom HTML tag tokenizer with a suitable maintained pure-PHP HTML-to-text library behind the existing converter boundary. Do not assume `ext-dom` is available. Preserve paragraphs, lists, table rows and the existing plain-text cleanup behavior, with fixture and unit coverage.
- Keep domain-specific logic such as parish matching, newsletter/quote cleanup and PDF column ordering in application code. A narrow custom helper is appropriate only when no suitable compatible library exists or when it is genuinely domain-specific; document the exception and its tradeoffs in an ADR.
- Keep dependency changes subject to the project's existing review requirements: ask the owner before adding a paid service or a non-pure-PHP dependency. Bundle and namespace-prefix approved runtime dependencies in the release zip.

## Consequences
- ADR 0013's prohibition on third-party IMAP packages and ADR 0012's custom-tokenizer decision no longer apply. The MIME parser and PDF parser choices remain in force.
- The existing IMAP client and HTML converter are legacy implementations, not evidence that this decision is already implemented. Replace them in separately scoped, tested changes; until then, keep their safety checks and tests intact. Update the architecture, development and hosting descriptions when each replacement lands.
- The release zip may grow, but dependency and maintenance tradeoffs will be assessed against correctness and compatibility rather than minimized by default.
