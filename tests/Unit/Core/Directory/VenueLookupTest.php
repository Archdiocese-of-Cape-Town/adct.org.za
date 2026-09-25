<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Directory;

use ADCT\ParishIntake\Core\Directory\Venue;
use ADCT\ParishIntake\Core\Directory\VenueLookup;
use ADCT\ParishIntake\Core\Ports\VenueLookupRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class VenueLookupTest extends TestCase
{
    public function testVenueNameMatchReturnsItsParishAndLocation(): void
    {
        $lookup = new VenueLookup(new FixedVenueLookupRepository([
            new Venue(
                14,
                7,
                "St Mary's Hall",
                [],
                '14 Example Street',
                'Sample Suburb',
                -33.9,
                18.4,
                true
            ),
        ]));

        $match = $lookup->match('The gathering is at St Mary’s Hall.', 7);

        self::assertNotNull($match);
        self::assertSame(14, $match->venueId);
        self::assertSame(7, $match->parishId);
        self::assertSame(-33.9, $match->latitude);
        self::assertSame(18.4, $match->longitude);
    }

    public function testAliasesMatchSaintAndStVariantsAndIgnoreChurchOrHallSuffixes(): void
    {
        $lookup = new VenueLookup(new FixedVenueLookupRepository([
            new Venue(
                21,
                9,
                "St Mary's Church",
                ["Saint Mary's Hall"],
                null,
                null,
                null,
                null,
                false
            ),
        ]));

        foreach ([
            'at Saint Marys',
            'at St. Mary’s Hall',
            "at Saint Mary's Church",
        ] as $text) {
            $match = $lookup->match($text, 9);
            self::assertNotNull($match, $text);
            self::assertSame(21, $match->venueId, $text);
        }
    }

    public function testParishMatchIsPreferredWhenVenueNamesAreShared(): void
    {
        $lookup = new VenueLookup(new FixedVenueLookupRepository([
            new Venue(31, 4, "St Mary's Hall", [], null, null, -33.9, 18.4, false),
            new Venue(32, 5, "St Mary's Hall", [], null, null, -34.0, 18.5, false),
        ]));

        $result = $lookup->lookup("St Mary's Hall", 5);

        self::assertNotNull($result->match);
        self::assertSame(32, $result->match->venueId);
        self::assertNotSame([], $result->notes);
    }

    public function testAmbiguousNameWithoutParishReturnsNoMatchAndExplainsWhy(): void
    {
        $lookup = new VenueLookup(new FixedVenueLookupRepository([
            new Venue(41, 6, 'Community Hall', [], null, null, null, null, false),
            new Venue(42, 7, 'Community Hall', [], null, null, null, null, false),
        ]));

        $result = $lookup->lookup('The event is at Community Hall.');

        self::assertNull($result->match);
        self::assertNotSame([], $result->notes);
        self::assertStringContainsString('more than one', strtolower(implode(' ', $result->notes)));
    }

    public function testDefaultVenueLookupReturnsLocationOnlyForAnActiveDefault(): void
    {
        $lookup = new VenueLookup(new FixedVenueLookupRepository([
            new Venue(51, 8, 'Main Church', [], null, null, -33.8, 18.3, true),
            new Venue(52, 8, 'Old Hall', [], null, null, -33.7, 18.2, true, Venue::INACTIVE),
        ]));

        $match = $lookup->defaultVenueFor(8);

        self::assertNotNull($match);
        self::assertSame(51, $match->venueId);
        self::assertSame(-33.8, $match->latitude);
        self::assertSame(18.3, $match->longitude);
        self::assertNull($lookup->defaultVenueFor(80));
    }
}

final class FixedVenueLookupRepository implements VenueLookupRepositoryInterface
{
    /**
     * @param list<Venue> $venues
     */
    public function __construct(private array $venues)
    {
    }

    public function findActiveVenues(): array
    {
        return array_values(array_filter(
            $this->venues,
            static fn (Venue $venue): bool => $venue->status === Venue::ACTIVE
        ));
    }
}
