<?php

declare(strict_types=1);

require_once __DIR__ . '/Support/PdfFixtureIntegrity.php';

\ADCT\ParishIntake\Tests\Support\PdfFixtureIntegrity::verify(__DIR__ . '/fixtures/pdfs');

require_once dirname(__DIR__) . '/vendor/autoload.php';
