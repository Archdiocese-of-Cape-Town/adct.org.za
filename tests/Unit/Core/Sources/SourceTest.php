<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Sources;

use ADCT\ParishIntake\Core\Sources\Source;
use ADCT\ParishIntake\Core\Sources\SourceRole;
use ADCT\ParishIntake\Core\Sources\SourceStatus;
use ADCT\ParishIntake\Core\Sources\SourceType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SourceTest extends TestCase
{
    public function testEverySourceTypeValidatesItsIdentifierShape(): void
    {
        self::assertSame(
            'office@example.test',
            (new Source(0, 7, SourceType::EMAIL, ' Office@Example.Test '))->identifier
        );

        foreach ([
            SourceType::ICS,
            SourceType::PDF_URL,
            SourceType::FACEBOOK_PAGE,
            SourceType::RSS,
            SourceType::WEB_PAGE,
        ] as $type) {
            self::assertSame(
                'https://example.test/source',
                (new Source(0, null, $type, ' https://example.test/source '))->identifier
            );
        }

        self::assertSame(
            'Forwarded parish bulletin',
            (new Source(0, 7, SourceType::WHATSAPP_FORWARD, ' Forwarded parish bulletin '))->identifier
        );
        self::assertSame(
            'Manual entry',
            (new Source(0, null, SourceType::MANUAL, ' Manual entry '))->identifier
        );
    }

    public function testInvalidIdentifiersAreRejectedForEachType(): void
    {
        $invalidIdentifiers = [
            [SourceType::EMAIL, 'not-an-email'],
            [SourceType::ICS, 'ftp://example.test/calendar.ics'],
            [SourceType::PDF_URL, 'https://'],
            [SourceType::FACEBOOK_PAGE, 'javascript:alert(1)'],
            [SourceType::RSS, 'http://user:password@example.test/feed'],
            [SourceType::WEB_PAGE, 'https://example.test/' . str_repeat('a', 200)],
            [SourceType::WHATSAPP_FORWARD, ''],
            [SourceType::MANUAL, "Manual\x01entry"],
        ];

        foreach ($invalidIdentifiers as [$type, $identifier]) {
            try {
                new Source(0, null, $type, $identifier);
                self::fail('An invalid identifier was accepted for ' . $type . '.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testSourceTypeRoleStatusAndPollIntervalAreValidated(): void
    {
        self::assertSame(8, count(SourceType::values()));
        self::assertSame(SourceRole::MONITORED, (new Source(0, 1, SourceType::EMAIL, 'office@example.test'))->role);
        self::assertSame(SourceStatus::ACTIVE, (new Source(0, 1, SourceType::EMAIL, 'office@example.test'))->status);
        self::assertSame(
            Source::DEFAULT_POLL_INTERVAL_MINUTES,
            (new Source(0, 1, SourceType::EMAIL, 'office@example.test'))->pollIntervalMinutes
        );
        self::assertSame(
            Source::MINIMUM_POLL_INTERVAL_MINUTES,
            (new Source(0, 1, SourceType::EMAIL, 'office@example.test', pollIntervalMinutes: 10))->pollIntervalMinutes
        );
        self::assertNull((new Source(0, 1, SourceType::MANUAL, 'Manual entry'))->pollIntervalMinutes);

        foreach ([
            ['unsupported', 'source@example.test', SourceRole::MONITORED, SourceStatus::ACTIVE, null],
            [SourceType::EMAIL, 'source@example.test', 'primary', SourceStatus::ACTIVE, null],
            [SourceType::EMAIL, 'source@example.test', SourceRole::MONITORED, 'unknown', null],
            [SourceType::EMAIL, 'source@example.test', SourceRole::MONITORED, SourceStatus::ACTIVE, 9],
        ] as [$type, $identifier, $role, $status, $interval]) {
            try {
                new Source(
                    0,
                    null,
                    $type,
                    $identifier,
                    $role,
                    $status,
                    $interval
                );
                self::fail('An invalid source field was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
