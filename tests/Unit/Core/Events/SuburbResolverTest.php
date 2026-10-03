<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Events;

use ADCT\ParishIntake\Core\Events\KnownPlace;
use ADCT\ParishIntake\Core\Events\NearMePoint;
use ADCT\ParishIntake\Core\Events\SuburbResolver;
use ADCT\ParishIntake\Core\Ports\PlaceCoordinateLookupInterface;
use PHPUnit\Framework\TestCase;

/** A stand-in for the parish and venue tables, so the matching rules are tested on their own. */
final class FakePlaceCoordinateLookup implements PlaceCoordinateLookupInterface
{
    /** @param list<KnownPlace> $places */
    public function __construct(private readonly array $places = [])
    {
    }

    /** @return list<KnownPlace> */
    public function placesWithCoordinates(int $limit): array
    {
        return array_slice($this->places, 0, $limit);
    }

    /** @return list<string> */
    public function suburbNames(int $limit): array
    {
        $names = [];
        foreach ($this->places as $place) {
            $names[$place->name] = true;
        }
        $names = array_keys($names);
        sort($names, SORT_STRING);

        return array_slice($names, 0, $limit);
    }
}

final class SuburbResolverTest extends TestCase
{
    /**
     * A small slice of the archdiocese: two parishes in Claremont, one in Bishopscourt, one whose
     * name and suburb disagree, and one church whose name carries a saint.
     */
    private function lookup(): FakePlaceCoordinateLookup
    {
        return new FakePlaceCoordinateLookup([
            new KnownPlace('St Mary Claremont', -33.957000, 18.476000),
            new KnownPlace('Claremont', -33.953000, 18.470000),
            new KnownPlace('Claremont North', -33.940000, 18.460000),
            new KnownPlace('Bishopscourt', -33.917000, 18.447000),
            new KnownPlace('All Saints', -33.905000, 18.520000),
            new KnownPlace('Sea Point', -33.806000, 18.380000),
            new KnownPlace("St George's", -33.950000, 18.500000),
            new KnownPlace('Oosterzee', -33.930000, 18.505000),
        ]);
    }

    public function testAnExactNameUsesThatOnePin(): void
    {
        $point = (new SuburbResolver($this->lookup()))->resolve('Bishopscourt');

        self::assertNotNull($point);
        self::assertSame('Bishopscourt', $point->describe());
        self::assertEqualsWithDelta(-33.917000, $point->latitude, 1e-9);
        self::assertEqualsWithDelta(18.447000, $point->longitude, 1e-9);
    }

    public function testSeveralPinsUnderOneNameAreAveragedIntoACentre(): void
    {
        /**
         * Only pins whose own name is exactly "Claremont" are averaged. St Mary Claremont holds
         * Claremont as its suburb, but its name carries the church, so it is a different place to
         * somebody standing in Claremont and must not drag the centre off towards Rondebosch.
         */
        $point = (new SuburbResolver(new FakePlaceCoordinateLookup([
            new KnownPlace('Claremont', -33.953000, 18.470000),
            new KnownPlace('Claremont', -33.957000, 18.476000),
            new KnownPlace('St Mary Claremont', -33.930000, 18.500000),
        ])))->resolve('Claremont');

        self::assertNotNull($point);
        self::assertSame('Claremont', $point->describe());
        self::assertEqualsWithDelta(-33.955000, $point->latitude, 1e-6);
        self::assertEqualsWithDelta(18.473000, $point->longitude, 1e-6);
    }

    public function testAnExactNameBeatsAPrefix(): void
    {
        /** "Claremont" is its own pin, so it must not resolve to Claremont North. */
        $point = (new SuburbResolver($this->lookup()))->resolve('Claremont');

        self::assertNotNull($point);
        self::assertNotSame('Claremont North', $point->describe());
    }

    public function testAPrefixIsAcceptedWhenThereIsNoExactName(): void
    {
        $point = (new SuburbResolver($this->lookup()))->resolve('Bishop');

        self::assertNotNull($point);
        self::assertSame('Bishopscourt', $point->describe());
    }

    public function testASubstringIsTheLastResort(): void
    {
        $resolver = new SuburbResolver($this->lookup());

        /** An exact name is preferred, but a word from the middle of one still finds the place. */
        self::assertSame('Bishopscourt', $resolver->resolve('bishopscourt')->describe());
        self::assertSame('Bishopscourt', $resolver->resolve('BISHOPSCOURT')->describe());
        self::assertSame('Bishopscourt', $resolver->resolve('  Bishopscourt  ')->describe());
        self::assertSame('All Saints', $resolver->resolve('saints')->describe());
        self::assertSame("St George's", $resolver->resolve('georg')->describe());
    }

    public function testMatchingIgnoresCaseAccentsSpacingAndPunctuation(): void
    {
        $resolver = new SuburbResolver($this->lookup());

        /**
         * An apostrophe elides a letter, so the two ways of typing a saint's church both land on
         * it. Without that, "St George's" would fold to "st george s" and never match anything.
         */
        foreach (["St George's", 'st georges', 'ST GEORGE S', "st  george's"] as $typed) {
            $point = $resolver->resolve($typed);

            self::assertNotNull($point, 'No match for ' . json_encode($typed));
            self::assertSame("St George's", $point->describe(), 'Wrong place for ' . json_encode($typed));
        }

        self::assertEqualsWithDelta(
            -33.950000,
            $resolver->resolve("St George's")->latitude,
            1e-9
        );

        /**
         * Accents fold off, so a visitor typing on a keyboard without them still finds the place,
         * and the name we show back is the archdiocese's own spelling of it.
         */
        $accented = new SuburbResolver(new FakePlaceCoordinateLookup([
            new KnownPlace('Réunion', -33.917000, 18.447000),
        ]));

        self::assertSame('Réunion', $accented->resolve('Reunion')->describe());
        self::assertSame('Réunion', $accented->resolve('RÉUNION')->describe());
        self::assertEqualsWithDelta(-33.917000, $accented->resolve('reunion')->latitude, 1e-9);

        /** A missing space still finds it too, because both sides fold to the same key. */
        self::assertSame('Sea Point', $resolver->resolve('Seapoint')->describe());
    }

    public function testAnUnknownSuburbIsReportedRatherThanGuessedAt(): void
    {
        $resolver = new SuburbResolver($this->lookup());

        self::assertNull($resolver->resolve('Nowhereville'));
        self::assertNull($resolver->resolve('   '));
        self::assertNull($resolver->resolve(''));
        self::assertNull((new SuburbResolver(new FakePlaceCoordinateLookup()))->resolve('Claremont'));
    }

    public function testTheChosenRadiusIsCarriedThrough(): void
    {
        $resolver = new SuburbResolver($this->lookup());

        self::assertSame(100.0, $resolver->resolve('Bishopscourt', 100.0)->radiusKm);
        self::assertSame(NearMePoint::DEFAULT_RADIUS_KM, $resolver->resolve('Bishopscourt')->radiusKm);
    }

    public function testTheSameSuburbAlwaysResolvesToTheSamePoint(): void
    {
        $places = [
            new KnownPlace('Claremont', -33.953000, 18.470000),
            new KnownPlace('St Mary Claremont', -33.957000, 18.476000),
            new KnownPlace('Another Claremont', -33.951000, 18.468000),
        ];

        $first = (new SuburbResolver(new FakePlaceCoordinateLookup($places)))->resolve('Claremont');
        $reversed = (new SuburbResolver(new FakePlaceCoordinateLookup(array_reverse($places))))->resolve('Claremont');

        self::assertNotNull($first);
        self::assertNotNull($reversed);
        self::assertSame($first->describe(), $reversed->describe());
        self::assertSame($first->latitude, $reversed->latitude);
        self::assertSame($first->longitude, $reversed->longitude);
    }

    public function testSuggestionsComeFromTheSamePlaceList(): void
    {
        $suggestions = (new SuburbResolver($this->lookup()))->suggestions();

        self::assertContains('Bishopscourt', $suggestions);
        self::assertContains('Claremont', $suggestions);
        self::assertContains("St George's", $suggestions);
        self::assertSame(array_values(array_unique($suggestions)), $suggestions);
    }

    public function testTheLookupIsCappedSoTheArchiveCannotBeDraggedIn(): void
    {
        $places = [];
        for ($index = 0; $index < 50; $index++) {
            $places[] = new KnownPlace('Place ' . $index, -33.0 - $index / 1000, 18.0);
        }

        $suggestions = (new SuburbResolver(new FakePlaceCoordinateLookup($places)))->suggestions();

        self::assertLessThanOrEqual(SuburbResolver::MAX_SUGGESTIONS, count($suggestions));
    }
}