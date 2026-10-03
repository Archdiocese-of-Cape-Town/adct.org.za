<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Events;

use ADCT\ParishIntake\Core\Events\IcsFeedLinks;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IcsFeedLinks::class)]
final class IcsFeedLinksTest extends TestCase
{
    private const TYPES = [
        7 => ['name' => 'Retreat', 'slug' => 'retreat'],
        9 => ['name' => 'Parish feast', 'slug' => 'parish-feast'],
        12 => ['name' => 'Retreat day', 'slug' => 'retreat-day'],
    ];

    public function testAnUnfilteredListingOffersTheWholeFeed(): void
    {
        $links = $this->links(null, null, []);

        self::assertCount(1, $links);
        self::assertSame('Subscribe to all events', $links[0]['label']);
        self::assertNull($links[0]['parish']);
        self::assertNull($links[0]['type']);
    }

    public function testAParishListingOffersOnlyThatParish(): void
    {
        $links = $this->links(4, 'St Mary’s, Rondebosch', []);

        self::assertCount(1, $links);
        self::assertSame('Subscribe to all events for St Mary’s, Rondebosch', $links[0]['label']);
        self::assertSame(4, $links[0]['parish']);
        self::assertNull($links[0]['type']);
    }

    public function testABlankParishNameFallsBackToTheGenericLabel(): void
    {
        $links = $this->links(4, '   ', []);

        self::assertSame('Subscribe to all events', $links[0]['label']);
        self::assertSame(4, $links[0]['parish'], 'The feed is still scoped to the parish.');
    }

    public function testASingleEventTypeIsOfferedByItsSlug(): void
    {
        $links = $this->links(null, null, [7]);

        self::assertCount(1, $links);
        self::assertSame('Subscribe to Retreat events', $links[0]['label']);
        self::assertSame('retreat', $links[0]['type']);
        self::assertNull($links[0]['parish']);
    }

    public function testAParishAndASingleTypeAreOfferedTogether(): void
    {
        $links = $this->links(4, 'St Mary’s, Rondebosch', [9]);

        self::assertSame('Subscribe to Parish feast events at St Mary’s, Rondebosch', $links[0]['label']);
        self::assertSame(4, $links[0]['parish']);
        self::assertSame('parish-feast', $links[0]['type']);
    }

    public function testSeveralTypesProduceOneFeedEachSoScopeIsNeverSilentlyWidened(): void
    {
        $links = $this->links(null, null, [7, 12]);

        self::assertCount(2, $links);
        self::assertSame(['retreat', 'retreat-day'], array_column($links, 'type'));
    }

    public function testUnknownTermIdsAreIgnored(): void
    {
        $links = $this->links(null, null, [7, 999]);

        self::assertSame(['retreat'], array_column($links, 'type'));
    }

    public function testTermIdsThatAreNotInTheCatalogueFallBackToTheWidestFeed(): void
    {
        $links = $this->links(null, null, [999]);

        self::assertSame(['Subscribe to all events'], array_column($links, 'label'));
        self::assertNull($links[0]['type']);
    }

    public function testDuplicateTermIdsProduceOneFeedEach(): void
    {
        $links = $this->links(null, null, [7, 7]);

        self::assertSame(['retreat'], array_column($links, 'type'));
    }

    public function testManyTypesAreCappedSoTheListingCannotGrowWithoutBound(): void
    {
        $catalogue = [];
        foreach (range(1, IcsFeedLinks::MAX_LINKS + 5) as $id) {
            $catalogue[$id] = ['name' => 'Type ' . $id, 'slug' => 'type-' . $id];
        }
        $links = (new IcsFeedLinks($catalogue))->links(null, null, array_keys($catalogue));

        self::assertCount(IcsFeedLinks::MAX_LINKS, $links);
    }

    public function testANonIntegerTermIdIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->links(null, null, ['7']);
    }

    public function testANonPositiveParishIdIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->links(0, 'Anywhere', []);
    }

    public function testACatalogueEntryWithoutASlugIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new IcsFeedLinks([7 => ['name' => 'Retreat', 'slug' => '']]);
    }

    public function testWebcalRewritesOnlyTheSchemeAndLeavesThePathAlone(): void
    {
        self::assertSame(
            'webcal://adct.org.za/?adct_ics=1',
            IcsFeedLinks::webcal('https://adct.org.za/?adct_ics=1')
        );
        self::assertSame(
            'webcal://adct.org.za/?adct_ics=1',
            IcsFeedLinks::webcal('http://adct.org.za/?adct_ics=1')
        );
        self::assertSame(
            'webcal://adct.org.za/path/?adct_ics=1&parish=4',
            IcsFeedLinks::webcal('https://adct.org.za/path/?adct_ics=1&parish=4')
        );
    }

    public function testWebcalLeavesAUrlWithoutAKnownSchemeUntouched(): void
    {
        self::assertSame('/?adct_ics=1', IcsFeedLinks::webcal('/?adct_ics=1'));
    }

    /**
     * @param list<int> $typeIds
     * @return list<array{label: string, parish: int|null, type: string|null}>
     */
    private function links(?int $parish, ?string $parishName, array $typeIds): array
    {
        return (new IcsFeedLinks(self::TYPES))->links($parish, $parishName, $typeIds);
    }
}