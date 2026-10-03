<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Events;

use ADCT\ParishIntake\Core\Events\KnownPlace;
use ADCT\ParishIntake\Core\Events\NearMePoint;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NearMePointTest extends TestCase
{
    public function testABrowserLocationCarriesNoPlaceName(): void
    {
        $point = NearMePoint::fromBrowserLocation(-33.957000, 18.476000);

        self::assertFalse($point->isSuburb());
        self::assertNull($point->label());
        self::assertSame('your location', $point->describe());
        self::assertSame(NearMePoint::DEFAULT_RADIUS_KM, $point->radiusKm);
        self::assertEqualsWithDelta(-33.957000, $point->latitude, 1e-9);
        self::assertEqualsWithDelta(18.476000, $point->longitude, 1e-9);
    }

    public function testASuburbIsNamedSoTheScreenCanSayWhichPlaceItUsed(): void
    {
        $point = NearMePoint::fromSuburb('Claremont', -33.957000, 18.476000, 50.0);

        self::assertTrue($point->isSuburb());
        self::assertSame('Claremont', $point->describe());
        self::assertSame(50.0, $point->radiusKm);
    }

    public function testASuburbNameIsTrimmedAndBounded(): void
    {
        self::assertSame('Claremont', NearMePoint::fromSuburb('  Claremont  ', -33.9, 18.4)->label());

        foreach (['', '   ', str_repeat('x', 101)] as $label) {
            try {
                NearMePoint::fromSuburb($label, -33.9, 18.4);
                self::fail('An unusable suburb name was accepted: ' . json_encode($label));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    /** @param array{0: float, 1: float, 2: float} $case */
    #[DataProvider('offTheMapCoordinates')]
    public function testCoordinatesOffTheMapAreRefused(float $latitude, float $longitude, float $radiusKm): void
    {
        $this->expectException(InvalidArgumentException::class);

        NearMePoint::fromBrowserLocation($latitude, $longitude, $radiusKm);
    }

    /** @return array<string, array{0: float, 1: float, 2: float}> */
    public static function offTheMapCoordinates(): array
    {
        return [
            'latitude past the pole' => [91.0, 18.4, 25.0],
            'longitude past the antimeridian' => [-33.9, 180.1, 25.0],
            'not a number' => [NAN, 18.4, 25.0],
        ];
    }

    public function testOnlyTheOfferedRadiiAreAccepted(): void
    {
        foreach (NearMePoint::RADIUS_CHOICES_KM as $choice) {
            self::assertSame((float) $choice, NearMePoint::knownRadius((float) $choice));
            self::assertSame((float) $choice, NearMePoint::fromBrowserLocation(0.0, 0.0, (float) $choice)->radiusKm);
        }

        /** 25 km has to be on the ladder, because it is what the form defaults to. */
        self::assertContains(NearMePoint::DEFAULT_RADIUS_KM, NearMePoint::RADIUS_CHOICES_KM);

        foreach ([0.0, 1.0, 26.0, 500.0, -25.0] as $radius) {
            try {
                NearMePoint::knownRadius($radius);
                self::fail('A radius off the ladder was accepted: ' . $radius);
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testRadiiReadAsAChoiceRatherThanANumber(): void
    {
        self::assertSame('Within 5 km', NearMePoint::radiusLabel(5.0));
        self::assertSame('Within 25 km', NearMePoint::radiusLabel(25.0));
        self::assertSame('Up to 100 km away', NearMePoint::radiusLabel(100.0));
    }

    /**
     * The three floats the SQL expects, in the order its placeholders appear. Getting this order
     * wrong would silently sort by the wrong distance, so it is pinned here.
     */
    public function testDistanceArgumentsAreLatitudeLatitudeLongitude(): void
    {
        $arguments = NearMePoint::fromBrowserLocation(-33.957, 18.476)->distanceArguments();

        self::assertCount(3, $arguments);
        self::assertSame(-33.957, $arguments[0]);
        self::assertSame(-33.957, $arguments[1]);
        self::assertSame(18.476, $arguments[2]);
    }

    public function testAKnownPlaceRefusesAImpossibleCoordinate(): void
    {
        $place = new KnownPlace('Claremont', -33.957, 18.476);

        self::assertSame('Claremont', $place->name);

        foreach ([['', 0.0, 0.0], ['Claremont', 91.0, 0.0], [str_repeat('x', 192), 0.0, 0.0]] as $bad) {
            try {
                new KnownPlace($bad[0], $bad[1], $bad[2]);
                self::fail('An unusable place was accepted: ' . json_encode($bad));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}