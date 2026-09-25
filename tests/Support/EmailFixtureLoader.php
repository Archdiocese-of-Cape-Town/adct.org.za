<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Support;

use ADCT\ParishIntake\Core\Ingestion\MimeMessageParser;
use ADCT\ParishIntake\Core\Parsing\Input\Message;
use RuntimeException;

final class EmailFixtureLoader
{
    public function load(string $path): Message
    {
        $source = file_get_contents($path);

        if (! is_string($source)) {
            throw new RuntimeException('Unable to read email fixture: ' . $path);
        }

        $message = (new MimeMessageParser())->parse($source, pathinfo($path, PATHINFO_FILENAME));

        if ($message->getSenderEmail() === '') {
            throw new RuntimeException('Email fixture is missing its From header: ' . $path);
        }

        $date = $message->getRawHeader('Date');

        if ($date === null || trim($date) === '') {
            throw new RuntimeException('Email fixture is missing its Date header: ' . $path);
        }

        if (! preg_match('/(?:\b(?:UT|UTC|GMT|Z)|[+-]\d{2}:?\d{2})$/i', trim($date))) {
            throw new RuntimeException('Email fixture Date header must include an explicit timezone: ' . $path);
        }

        if ($message->getReceivedAt() === null) {
            throw new RuntimeException('Email fixture has an invalid Date header: ' . $path);
        }

        return $message;
    }
}
