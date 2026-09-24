<?php

$composerAutoloader = __DIR__ . '/../vendor/autoload.php';

if (is_file($composerAutoloader)) {
    require_once $composerAutoloader;
} else {
    require_once __DIR__ . '/../src/WordPress/Autoloader.php';
    ADCT\ParishIntake\WordPress\Autoloader::register();
}

use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\PipelineFactory;

$pipeline = (new PipelineFactory())->create();

$cases = [
    new Message(
        'email',
        'smoke-1',
        'secretary@stmarysparish.org.za',
        'St Mary Parish Office',
        'Healing Mass on First Friday',
        "Parish: St Mary Parish\nJoin us every first Friday at 18:00 at St Mary Parish Hall for a healing Mass. Contact office@stmarysparish.org.za"
    ),
    new Message(
        'email',
        'smoke-2',
        'news@holyfamily.org.za',
        'Holy Family Parish',
        'Youth fundraiser 12 Oct 2026',
        "Our youth fundraiser takes place on 12 Oct 2026 at 19:00. Venue: Holy Family Hall. Contact 021 555 1111."
    ),
];

foreach ($cases as $index => $message) {
    $result = $pipeline->parse($message)->toArray();

    if (($result['fields']['title'] ?? null) === null) {
        fwrite(STDERR, 'Missing title in case ' . ($index + 1) . PHP_EOL);
        exit(1);
    }

    if (($result['classification'] ?? 'unknown') === 'unknown') {
        fwrite(STDERR, 'Unknown classification in case ' . ($index + 1) . PHP_EOL);
        exit(1);
    }
}

echo "Parser smoke tests passed\n";
