<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Parsing;

use ADCT\ParishIntake\Core\Directory\DirectorySnapshot;
use ADCT\ParishIntake\Core\Directory\SenderTrust;
use ADCT\ParishIntake\Core\Directory\Venue;
use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\PipelineFactory;
use ADCT\ParishIntake\Core\Parsing\ParseResult;
use ADCT\ParishIntake\Core\Ports\DirectorySnapshotProviderInterface;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DirectoryLookupStageTest extends TestCase
{
    public function testVerifiedSingleParishSenderFillsParishAndDefaultVenue(): void
    {
        $result = self::parse(
            self::snapshot(),
            'events@example.test',
            'Please join the community gathering on Sunday 11 October 2026 at 16:00.'
        );

        self::assertSame('Example District: St Mary\'s', $result->getField('parish_name'));
        self::assertSame(101, $result->getField('parish_id'));
        self::assertSame('St Mary\'s Hall', $result->getField('venue'));
        self::assertSame(501, $result->getField('venue_id'));
        self::assertSame(-33.91, $result->getField('venue_latitude'));
        self::assertSame(18.42, $result->getField('venue_longitude'));
        self::assertSame([
            'source' => 'sender',
            'confidence' => 1.0,
            'parish_id' => 101,
        ], $result->getField('parish_match'));
        self::assertSame([
            'source' => 'default',
            'confidence' => 1.0,
            'venue_id' => 501,
            'parish_id' => 101,
        ], $result->getField('venue_match'));
        self::assertStringContainsString('parish_match: sender', implode("\n", $result->getNotes()));
        self::assertStringContainsString('venue_match: default', implode("\n", $result->getNotes()));
    }

    public function testVerifiedSenderDoesNotAutoFillAnInactiveParish(): void
    {
        $result = self::parse(
            self::snapshot(),
            'inactive@example.test',
            'A community gathering is on Sunday 11 October 2026 at 16:00.'
        );

        self::assertNull($result->getField('parish_id'));
        self::assertNull($result->getField('venue_id'));
        self::assertStringContainsString('No directory parish match', implode("\n", $result->getNotes()));
    }

    public function testVerifiedMultiParishSenderUsesTextToDisambiguate(): void
    {
        $result = self::parse(
            self::snapshot(),
            'shared@example.test',
            'The St. Mary gathering in Fictionalville is on 11 October 2026 at 16:00.'
        );

        self::assertSame(101, $result->getField('parish_id'));
        self::assertSame('text', $result->getField('parish_match')['source'] ?? null);
        self::assertStringContainsString('parish_match: text', implode("\n", $result->getNotes()));
    }

    public function testVerifiedSenderSupportKeepsChurchNameOnlyTextMatchConfidence(): void
    {
        $result = self::parse(
            self::snapshot(),
            'john-shared@example.test',
            'Feast of St John the Baptist is on 11 October 2026 at 16:00.'
        );

        self::assertSame(105, $result->getField('parish_id'));
        self::assertSame('text', $result->getField('parish_match')['source'] ?? null);
        self::assertSame(0.9, $result->getField('parish_match')['confidence'] ?? null);
    }

    public function testVerifiedMultiParishSenderWithoutTextMatchLeavesParishUnresolved(): void
    {
        $result = self::parse(
            self::snapshot(),
            'shared@example.test',
            'The community gathering is on 11 October 2026 at 16:00.'
        );

        self::assertNull($result->getField('parish_id'));
        self::assertNull($result->getField('venue_id'));
        self::assertStringContainsString('linked to multiple parishes', implode("\n", $result->getNotes()));
    }

    public function testPendingSenderUsesTextButDoesNotAutoFillFromItsContactLink(): void
    {
        $result = self::parse(
            self::snapshot(),
            'pending@example.test',
            'The Holy Cross (Example Suburb) gathering is on 11 October 2026 at 16:00.'
        );

        self::assertSame(102, $result->getField('parish_id'));
        self::assertSame('text', $result->getField('parish_match')['source'] ?? null);
        self::assertSame(502, $result->getField('venue_id'));
        self::assertSame('Holy Cross Church', $result->getField('venue'));
        self::assertSame('text', $result->getField('venue_match')['source'] ?? null);
    }

    public function testUnresolvedMultiParishSenderCanStillResolveAnExplicitVenue(): void
    {
        $result = self::parse(
            self::snapshot(),
            'shared@example.test',
            "The community gathering is on 11 October 2026 at 16:00.\nVenue: Community Centre"
        );

        self::assertNull($result->getField('parish_id'));
        self::assertSame(503, $result->getField('venue_id'));
        self::assertSame('label', $result->getField('venue_match')['source'] ?? null);
        self::assertStringContainsString('linked to multiple parishes', implode("\n", $result->getNotes()));
    }

    #[DataProvider('parishNameVariants')]
    public function testParishNamesMatchAliasesAndSuburbQualifiers(string $parishText, int $expectedParishId): void
    {
        $result = self::parse(
            self::snapshot(),
            'unknown@example.test',
            'The gathering for ' . $parishText . ' is on 11 October 2026 at 16:00.'
        );

        self::assertSame($expectedParishId, $result->getField('parish_id'));
        self::assertContains($result->getField('parish_match')['source'] ?? null, ['text', 'context']);
        self::assertSame(0.9, $result->getField('parish_match')['confidence'] ?? null);
    }

    public function testChurchNameOnlyTextMatchUsesReducedConfidenceWithoutSenderSupport(): void
    {
        $result = self::parse(
            self::snapshot(),
            'unknown@example.test',
            'Feast of St John the Baptist is on 11 October 2026 at 16:00.'
        );

        self::assertSame(105, $result->getField('parish_id'));
        self::assertSame('text', $result->getField('parish_match')['source'] ?? null);
        self::assertSame(0.6, $result->getField('parish_match')['confidence'] ?? null);
        self::assertStringContainsString('parish_match: text (confidence 0.60', implode("\n", $result->getNotes()));
    }

    public static function parishNameVariants(): array
    {
        return [
            'apostrophe and suburb' => ['St Mary\'s, Fictionalville', 101],
            'Saint spelling' => ['Saint Marys, Fictionalville', 101],
            'punctuation and possessive variant' => ['St. Mary, Fictionalville', 101],
            'parenthesized suburb' => ['Holy Cross (Example Suburb)', 102],
        ];
    }

    public function testLabelledVenueMatchAttachesDirectoryIdAndLocationWithoutReplacingLabel(): void
    {
        $result = self::parse(
            self::snapshot(),
            'unknown@example.test',
            "A gathering is on 11 October 2026 at 16:00.\nVenue: Saint Marys Hall"
        );

        self::assertSame('Saint Marys Hall', $result->getField('venue'));
        self::assertSame(501, $result->getField('venue_id'));
        self::assertSame(-33.91, $result->getField('venue_latitude'));
        self::assertSame(18.42, $result->getField('venue_longitude'));
        self::assertSame('label', $result->getField('venue_match')['source'] ?? null);
        self::assertSame(501, $result->getField('venue_match')['venue_id'] ?? null);
    }

    public function testUnmatchedLabelledVenueIsPreservedInsteadOfBeingReplacedByDefault(): void
    {
        $result = self::parse(
            self::snapshot(),
            'events@example.test',
            "A gathering is on 11 October 2026 at 16:00.\nVenue: The Fictional Civic Garden"
        );

        self::assertSame('The Fictional Civic Garden', $result->getField('venue'));
        self::assertNull($result->getField('venue_id'));
        self::assertNull($result->getField('venue_match'));
        self::assertStringContainsString('labelled venue did not match', implode("\n", $result->getNotes()));
    }

    public function testUnmatchedSharedVenueContextIsPreservedForVerifiedSenders(): void
    {
        $result = self::parse(
            self::snapshot(),
            'events@example.test',
            "Venue: The Fictional Civic Garden\nOCTOBER 2026\nUPCOMING EVENTS\n"
                . '- Community gathering on Sunday 11 October 2026 at 16:00.'
        );

        self::assertSame(101, $result->getField('parish_id'));
        self::assertSame('The Fictional Civic Garden', $result->getField('venue'));
        self::assertNull($result->getField('venue_id'));
        self::assertNull($result->getField('venue_match'));
        self::assertStringContainsString('labelled venue did not match', implode("\n", $result->getNotes()));
    }

    public function testNoDirectoryMatchAddsNotesWithoutAddingDirectoryMatchFields(): void
    {
        $message = self::message(
            'unknown@example.test',
            'The astronomy gathering is on 11 October 2026 at 18:00.'
        );
        $expected = (new PipelineFactory())->create()->parse($message);
        $result = self::parse(self::snapshot(), $message->getSenderEmail(), $message->getBody());

        self::assertNull($result->getField('parish_id'));
        self::assertNull($result->getField('venue_id'));
        self::assertNull($result->getField('parish_match'));
        self::assertNull($result->getField('venue_match'));
        self::assertSame($expected->fields(), $result->fields());
        self::assertSame($expected->getStrategies(), $result->getStrategies());
        self::assertStringContainsString('No directory parish match', implode("\n", $result->getNotes()));
    }

    public function testBlockedSenderLeavesTheParsedResultUnchanged(): void
    {
        $message = self::message(
            'blocked@example.test',
            "Parish: St. Mary, Fictionalville\nA gathering is on 11 October 2026 at 16:00.\nVenue: Saint Marys Hall"
        );
        $expected = (new PipelineFactory())->create()->parse($message)->toArray();
        $actual = self::parse(self::snapshot(), $message->getSenderEmail(), $message->getBody())->toArray();

        self::assertSame($expected, $actual);
    }

    public function testLookupProvenanceIsPresentInSerializedFieldsAndNotes(): void
    {
        $serialized = self::parse(
            self::snapshot(),
            'events@example.test',
            'A gathering is on 11 October 2026 at 16:00.'
        )->toArray();

        self::assertSame(101, $serialized['fields']['parish_id'] ?? null);
        self::assertSame(501, $serialized['fields']['venue_id'] ?? null);
        self::assertSame('sender', $serialized['fields']['parish_match']['source'] ?? null);
        self::assertSame(1.0, $serialized['fields']['parish_match']['confidence'] ?? null);
        self::assertSame('default', $serialized['fields']['venue_match']['source'] ?? null);
        self::assertSame(1.0, $serialized['fields']['venue_match']['confidence'] ?? null);
    }

    private static function parse(DirectorySnapshot $snapshot, string $senderEmail, string $body): ParseResult
    {
        $provider = new FixedDirectoryLookupSnapshotProvider($snapshot);

        return (new PipelineFactory(null, $provider))
            ->create()
            ->parse(self::message($senderEmail, $body));
    }

    private static function message(string $senderEmail, string $body): Message
    {
        return new Message(
            'email',
            'directory-lookup-test',
            $senderEmail,
            '',
            'Community gathering',
            $body,
            [],
            new DateTimeImmutable('2026-09-25 09:00:00', new DateTimeZone('Africa/Johannesburg'))
        );
    }

    private static function snapshot(): DirectorySnapshot
    {
        return new DirectorySnapshot(
            [
                [
                    'id' => 101,
                    'name' => 'Example District: St Mary\'s',
                    'slug' => 'example-district-st-marys',
                    'area' => 'Example District',
                    'church' => 'St Mary\'s',
                    'suburb' => 'Fictionalville',
                    'aliases' => ['Saint Marys', 'St. Mary'],
                    'status' => 'active',
                ],
                [
                    'id' => 102,
                    'name' => 'Sample District: Holy Cross',
                    'slug' => 'sample-district-holy-cross',
                    'area' => 'Sample District',
                    'church' => 'Holy Cross',
                    'suburb' => 'Example Suburb',
                    'aliases' => ['Holy Cross Mission'],
                    'status' => 'active',
                ],
                [
                    'id' => 103,
                    'name' => 'Test District: Inactive Parish',
                    'slug' => 'test-district-inactive-parish',
                    'area' => 'Test District',
                    'church' => 'Inactive Parish',
                    'suburb' => 'Dormant Town',
                    'aliases' => [],
                    'status' => 'inactive',
                ],
                [
                    'id' => 104,
                    'name' => 'Test District: Other Parish',
                    'slug' => 'test-district-other-parish',
                    'area' => 'Test District',
                    'church' => 'Other Parish',
                    'suburb' => 'Sample Suburb',
                    'aliases' => [],
                    'status' => 'active',
                ],
                [
                    'id' => 105,
                    'name' => 'Example District: St John the Baptist',
                    'slug' => 'example-district-st-john-the-baptist',
                    'area' => 'Example District',
                    'church' => 'St John the Baptist',
                    'suburb' => 'Baptistville',
                    'aliases' => [],
                    'status' => 'active',
                ],
            ],
            [
                new Venue(
                    501,
                    101,
                    'St Mary\'s Hall',
                    ['Saint Marys Hall', 'St. Mary Hall'],
                    '1 Fictional Road',
                    'Fictionalville',
                    -33.91,
                    18.42,
                    true
                ),
                new Venue(
                    502,
                    102,
                    'Holy Cross Church',
                    ['Holy Cross Chapel'],
                    '2 Sample Lane',
                    'Example Suburb',
                    -33.92,
                    18.43,
                    true
                ),
                new Venue(
                    503,
                    104,
                    'Community Centre',
                    [],
                    '3 Sample Street',
                    'Sample Suburb',
                    -33.93,
                    18.44,
                    false
                ),
            ],
            [
                ['parish_id' => 101, 'email' => 'events@example.test', 'trust' => SenderTrust::VERIFIED],
                ['parish_id' => 101, 'email' => 'shared@example.test', 'trust' => SenderTrust::VERIFIED],
                ['parish_id' => 102, 'email' => 'shared@example.test', 'trust' => SenderTrust::VERIFIED],
                ['parish_id' => 101, 'email' => 'pending@example.test', 'trust' => SenderTrust::PENDING],
                ['parish_id' => 101, 'email' => 'blocked@example.test', 'trust' => SenderTrust::BLOCKED],
                ['parish_id' => 103, 'email' => 'inactive@example.test', 'trust' => SenderTrust::VERIFIED],
                ['parish_id' => 101, 'email' => 'john-shared@example.test', 'trust' => SenderTrust::VERIFIED],
                ['parish_id' => 105, 'email' => 'john-shared@example.test', 'trust' => SenderTrust::VERIFIED],
            ]
        );
    }
}

final class FixedDirectoryLookupSnapshotProvider implements DirectorySnapshotProviderInterface
{
    public function __construct(private DirectorySnapshot $snapshot)
    {
    }

    public function getSnapshot(): DirectorySnapshot
    {
        return $this->snapshot;
    }
}
