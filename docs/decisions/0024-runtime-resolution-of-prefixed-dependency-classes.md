# ADR 0024: Resolve prefixed dependency classes by name at runtime

- Status: Proposed
- Date: 2026-10-04

## Context
The release build runs Strauss (`composer strauss`) to copy the dependency tree into `vendor-prefixed/` under the `ADCT\ParishIntake\Dependencies\` namespace, which keeps two plugins that vendor the same library from colliding. Strauss rewrites the vendor packages themselves. It does **not** rewrite `use` statements in our own `src/`.

Issue [#239](https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/239) is what that costs us. `src/WordPress/Pdf/PrinsFrankPdfTextExtractor.php` imported `PrinsFrank\PdfParser\PdfParser` and `PrinsFrank\PdfParser\Exception\PdfParserException` with hard `use` statements. In the release those classes exist only under the prefix, so `new PdfParser()` threw `Error: Class "PrinsFrank\PdfParser\PdfParser" not found`. The adapter's `catch (Throwable)` then reported the misleading `The file could not be read as a PDF.` — so **every** PDF failed, in every released build, while saying the file was at fault. The defect was pre-existing on `main`, not introduced by the later column-detection work.

Two things let it ship. The build was green because `scripts/check-release-bootstrap.php` only asserted that prefixed classes *load*; a classmap that loads is a classmap that loads whether or not any adapter can reach it. And the failure mode was invisible because a catch-all `Throwable` collapsed a namespace-resolution bug into a user-facing "bad file" message. The same pattern was already handled correctly elsewhere — `src/Core/Ingestion/MimeMessageParser.php` and `src/Core/Ingestion/RawMessageInspector.php` resolve `MailMimeParser` by string — so the PDF adapter was the outlier, not the rule.

## Decision
- Our own source must not `use`, instantiate, catch or type-hint a dependency class through its unprefixed namespace. Resolve such a class **by string at runtime**, probing the unprefixed name first and the prefixed name second:

  ```php
  private const PDF_PARSER_CLASSES = [
      'PrinsFrank\\PdfParser\\PdfParser',
      'ADCT\\ParishIntake\\Dependencies\\PrinsFrank\\PdfParser\\PdfParser',
  ];
  ```

  Unprefixed first keeps development working against plain `vendor/`, where Strauss has not run. Probing rather than assuming means neither environment needs a special case. `@param` docblocks document these as `object`/`string` rather than as class types, because there is no class to name at compile time.
- A namespace-qualified string is the supported way to name a dependency class, and is explicitly **not** a violation of this decision.
- `scripts/check-release-bootstrap.php` must exercise **behaviour**, not just class loading. It now extracts text from a packaged fixture PDF (`assets/release-check/two-column-bulletin.pdf`) and fails unless the extracted text is non-empty, so an adapter that cannot reach the prefixed parser fails the release.
- The same check now tokenises every shipped `src/**/*.php` with `token_get_all()` and fails if any name token — `use` import, `new`, `catch`, parameter or return type — starts with a namespace this plugin actually depends on (`PrinsFrank\`, `ZBateson\`). Tokenising is what makes this precise: a namespace-qualified **string literal** is never a name token, so the runtime-resolution lists above are correctly ignored rather than being reported as violations of their own fix. The list is limited to namespaces we really depend on, so it cannot fire on a PHP builtin, a WordPress class or a symbol we declare.
- The check lives in `check-release-bootstrap.php` rather than in `validate-release-zip.sh`, because `build-release.sh` invokes the validator, which invokes the check. One place covers both scripts, and the guard is exercised by the same unit tests that already cover the check.

## Consequences
- The shipped PDF adapter resolves the prefixed parser, so text-layer PDFs extract again in release builds. Any future dependency import in our own `src/` fails the release build instead of shipping a latent `Error` behind a catch-all.
- The prefix is now a runtime string rather than a compile-time fact, so a dependency class is no longer autocompleted or statically checkable at the use site. In exchange, the failure is caught by the release gate rather than by the site. Nothing in the codebase uses static analysis that would have caught it anyway.
- Adding a dependency namespace to the `token_get_all()` list is required for that dependency's imports to be guarded. The list is intentionally narrow: an unguarded namespace fails silently as before, while an over-broad list would produce false positives on legitimate runtime-resolution code.
- `assets/release-check/two-column-bulletin.pdf` ships in the release zip (~1.2 KB) so the behavioural check has something real to read. It is synthetic and contains no parish, person or contact details.
