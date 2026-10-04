<?php

declare(strict_types=1);

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php check-release-bootstrap.php <plugin-directory>\n");
    exit(2);
}

$pluginDirectory = rtrim($argv[1], "/\\");
$pluginFile = $pluginDirectory . DIRECTORY_SEPARATOR . 'adct-parish-intake.php';

if (! is_file($pluginFile)) {
    fwrite(STDERR, "Plugin bootstrap is missing.\n");
    exit(1);
}

// adct-parish-intake.php requires vendor-prefixed/autoload.php unconditionally, so
// this guard has to run before the bootstrap is required: without it the require
// fatals on the missing file and none of the assertions below ever run. The message
// therefore has to name the build step that produces vendor-prefixed/ (composer
// strauss, run by scripts/build-release.sh), not the file it emits, so that a
// failure points at the step to run rather than at a file to hunt for.
if (! is_file($pluginDirectory . DIRECTORY_SEPARATOR . 'vendor-prefixed' . DIRECTORY_SEPARATOR . 'autoload.php')) {
    fwrite(STDERR, "Prefixed dependencies are missing: this directory has not been through the dependency prefixing step.\n");
    fwrite(STDERR, "vendor-prefixed/autoload.php is produced by the composer strauss prefixing step in scripts/build-release.sh.\n");
    fwrite(STDERR, "Run that build (or 'composer install --no-dev' plus 'COMPOSER_VENDOR_DIR=vendor-prefixed php <strauss.phar>') before this check.\n");
    exit(1);
}

define('ABSPATH', $pluginDirectory . DIRECTORY_SEPARATOR);

function register_activation_hook($file, $callback): void
{
}

function register_deactivation_hook($file, $callback): void
{
}

require $pluginFile;

if (! class_exists(ADCT\ParishIntake\WordPress\Autoloader::class, false)
    || ! class_exists(ADCT\ParishIntake\WordPress\Plugin::class, false)
    || ! class_exists(ADCT\ParishIntake\Core\Parsing\PipelineFactory::class)
    || ! class_exists(ADCT\ParishIntake\Core\Ingestion\MimeMessageParser::class)
    || ! class_exists(ADCT\ParishIntake\WordPress\Admin\ParserPage::class, false)
    || ! class_exists(ADCT\ParishIntake\WordPress\Admin\ScheduledJobsPage::class, false)
    || ! class_exists(ADCT\ParishIntake\WordPress\Database\Schema::class, false)
    || ! class_exists(ADCT\ParishIntake\WordPress\Jobs\WordPressJobScheduler::class, false)
    || ! class_exists(ADCT\ParishIntake\Core\Ingestion\Imap\ImapMailbox::class)
    || ! class_exists(ADCT\ParishIntake\Core\Ingestion\Imap\ImapProtocolClient::class)
    || ! class_exists(ADCT\ParishIntake\Core\Ingestion\Imap\MailboxConnectionConfig::class)
    || ! class_exists(ADCT\ParishIntake\Core\Ingestion\Imap\StreamTransport::class)
    || ! class_exists(ADCT\ParishIntake\Core\Ingestion\MailboxSearchCriteria::class)
    || ! class_exists(ADCT\ParishIntake\Core\Ingestion\RawMailMessage::class)
    || ! interface_exists(ADCT\ParishIntake\Core\Ingestion\Imap\TransportInterface::class)
) {
    fwrite(STDERR, "Plugin autoloader did not load the bootstrap classes.\n");
    exit(1);
}

foreach ([
    'DI\\Container' => 'ADCT\\ParishIntake\\Dependencies\\DI\\Container',
    'Laravel\\SerializableClosure\\SerializableClosure' => 'ADCT\\ParishIntake\\Dependencies\\Laravel\\SerializableClosure\\SerializableClosure',
    'GuzzleHttp\\Psr7\\Request' => 'ADCT\\ParishIntake\\Dependencies\\GuzzleHttp\\Psr7\\Request',
] as $unprefixedClass => $prefixedClass) {
    if (class_exists($unprefixedClass)) {
        fwrite(STDERR, "Unprefixed dependency class loaded from the release: {$unprefixedClass}\n");
        exit(1);
    }

    if (! class_exists($prefixedClass)) {
        fwrite(STDERR, "Prefixed dependency class did not load from the release: {$prefixedClass}\n");
        exit(1);
    }
}

$mimeMessage = (new ADCT\ParishIntake\Core\Ingestion\MimeMessageParser())->parse(
    "From: Example Parish Office <events@example.test>\r\n"
        . "Subject: Release parser check\r\n"
        . "Date: Thu, 01 Oct 2026 09:00:00 +0200\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "\r\n"
        . "Release parser body.",
    'release-bootstrap-check'
);

if (
    $mimeMessage->getSenderEmail() !== 'events@example.test'
    || $mimeMessage->getSubject() !== 'Release parser check'
    || $mimeMessage->getBody() !== 'Release parser body.'
) {
    fwrite(STDERR, "Release MIME parser did not decode the smoke message.\n");
    exit(1);
}

foreach ([
    ADCT\ParishIntake\Core\Ports\ClockInterface::class,
    ADCT\ParishIntake\Core\Ports\MailboxInterface::class,
    ADCT\ParishIntake\Core\Ports\MailerInterface::class,
    ADCT\ParishIntake\Core\Ports\EventRepositoryInterface::class,
    ADCT\ParishIntake\Core\Ports\AiProviderInterface::class,
    ADCT\ParishIntake\Core\Ports\OcrProviderInterface::class,
    ADCT\ParishIntake\Core\Ports\HttpClientInterface::class,
    ADCT\ParishIntake\Core\Ports\JobLockInterface::class,
    ADCT\ParishIntake\Core\Ports\JobStateStoreInterface::class,
] as $coreInterface) {
    if (! interface_exists($coreInterface)) {
        fwrite(STDERR, "Plugin source autoloader did not load {$coreInterface}.\n");
        exit(1);
    }
}

// Loading the classes proves nothing about reaching them. Issue #239 shipped a
// PDF extractor whose hard `use` statements named classes the prefixing step had
// renamed, so every PDF failed at runtime with a message about the file rather
// than the missing class, and this check stayed green. The PDF below is shipped
// in the package, so reading its text is the only assertion that exercises the
// adapter the way the site will.
$pdfFixture = $pluginDirectory . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR
    . 'release-check' . DIRECTORY_SEPARATOR . 'two-column-bulletin.pdf';

if (! is_file($pdfFixture)) {
    fwrite(STDERR, "Release check PDF fixture is missing from the package: assets/release-check/two-column-bulletin.pdf\n");
    fwrite(STDERR, "scripts/build-release.sh copies assets/ into the package; the fixture has to be shipped for this check to mean anything.\n");
    exit(1);
}

$pdfResult = (new ADCT\ParishIntake\WordPress\Pdf\PrinsFrankPdfTextExtractor())->extract(
    $pdfFixture,
    ADCT\ParishIntake\Core\Pdf\PdfExtractionLimits::defaults()
);

if ($pdfResult->status !== ADCT\ParishIntake\Core\Pdf\PdfExtractionResult::STATUS_EXTRACTED) {
    fwrite(STDERR, "Release PDF adapter did not extract text: status={$pdfResult->status} reason={$pdfResult->reason}\n");
    fwrite(STDERR, "The prefixed PDF parser must stay reachable from the release; see src/WordPress/Pdf/PrinsFrankPdfTextExtractor.php.\n");
    exit(1);
}

if (trim((string) $pdfResult->text) === '') {
    fwrite(STDERR, "Release PDF adapter extracted no text from assets/release-check/two-column-bulletin.pdf.\n");
    exit(1);
}

fwrite(STDOUT, "Plugin bootstrap loaded under plain PHP.\n");
fwrite(STDOUT, "Release PDF text extraction read the packaged fixture.\n");

// The prefixing step rewrites the dependency packages under vendor-prefixed/ but
// never rewrites `use` statements inside our own src/, so a hard import of a vendor
// namespace there ships pointing at a class the release no longer defines. Issue
// #239 shipped one and every assertion above still passed, because the classes loaded
// fine until something tried to use them.
//
// Only the namespaces this plugin actually depends on are listed, so this cannot fire
// on a PHP builtin, a WordPress class, or a symbol we declare ourselves.
//
// The scan uses the PHP tokenizer rather than a text search, which is what keeps the
// supported escape hatch legal: a namespace-qualified *string* is a string literal,
// never a name token, so the runtime-resolution lists in files such as
// src/Core/Ingestion/MimeMessageParser.php are correctly ignored. Every other shape --
// `use` imports, `new X\Y`, `catch (X\Y)`, parameter and return types -- arrives as a
// T_NAME_QUALIFIED/T_NAME_FULLY_QUALIFIED token in code position and is caught.
$vendorNamespaces = [
    'PrinsFrank\\',
    'ZBateson\\',
];

$nameTokens = [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];
$unprefixedReferences = [];

foreach (new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($pluginDirectory . DIRECTORY_SEPARATOR . 'src', FilesystemIterator::SKIP_DOTS)
) as $sourceFile) {
    if (! $sourceFile->isFile() || $sourceFile->getExtension() !== 'php') {
        continue;
    }

    $tokens = @token_get_all((string) file_get_contents($sourceFile->getPathname()));

    foreach ($tokens as $token) {
        if (! is_array($token) || ! in_array($token[0], $nameTokens, true)) {
            continue;
        }

        foreach ($vendorNamespaces as $vendorNamespace) {
            if (! str_starts_with($token[1], $vendorNamespace)) {
                continue;
            }

            $unprefixedReferences[] = sprintf(
                '%s line %d hard-references the unprefixed dependency namespace %s',
                str_replace($pluginDirectory . DIRECTORY_SEPARATOR, '', $sourceFile->getPathname()),
                $token[2],
                $vendorNamespace
            );
        }
    }
}

if ($unprefixedReferences !== []) {
    fwrite(STDERR, "Shipped plugin source references an unprefixed dependency namespace:\n");
    foreach ($unprefixedReferences as $reference) {
        fwrite(STDERR, "  {$reference}\n");
    }
    fwrite(STDERR, "The prefixing step does not rewrite src/, so resolve these class names at runtime (probing the unprefixed name first, then the prefixed one) as src/Core/Ingestion/MimeMessageParser.php does.\n");
    exit(1);
}

fwrite(STDOUT, "No shipped source file references an unprefixed dependency namespace.\n");