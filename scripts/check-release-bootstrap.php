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

if (! is_file($pluginDirectory . DIRECTORY_SEPARATOR . 'vendor-prefixed' . DIRECTORY_SEPARATOR . 'autoload.php')) {
    fwrite(STDERR, "Prefixed dependency autoloader is missing.\n");
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

fwrite(STDOUT, "Plugin bootstrap loaded under plain PHP.\n");
