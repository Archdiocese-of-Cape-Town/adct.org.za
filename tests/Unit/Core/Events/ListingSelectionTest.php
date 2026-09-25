<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Events;

use ADCT\ParishIntake\Core\Events\ListingSelection;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ListingSelectionTest extends TestCase
{
    public function testMultipleTypesAndDirectoryFiltersRoundTrip(): void
    {
        $selection = new ListingSelection([
            'adct_period' => 'range',
            'adct_from' => '2026-10-01',
            'adct_to' => '2026-10-31',
            'adct_page' => '2',
            'adct_types' => ['9', '3', '9'],
            'adct_parish' => '150',
            'adct_deanery' => '4',
        ]);

        self::assertSame([3, 9], $selection->types);
        self::assertSame(150, $selection->parish);
        self::assertSame(4, $selection->deanery);
        self::assertSame(2, $selection->page);
        self::assertSame([
            'adct_period' => 'range',
            'adct_from' => '2026-10-01',
            'adct_to' => '2026-10-31',
            'adct_types' => [3, 9],
            'adct_parish' => 150,
            'adct_deanery' => 4,
            'adct_page' => 2,
        ], $selection->query());
        self::assertSame($selection->query(), (new ListingSelection($selection->query()))->query());
    }

    public function testMalformedAndOversizedInputsAreRejected(): void
    {
        foreach ([
            ['adct_types' => '1'],
            ['adct_types' => [['1']]],
            ['adct_types' => range(1, 21)],
            ['adct_types' => ['0']],
            ['adct_types' => ['999999999999999999999']],
            ['adct_parish' => ['1']],
            ['adct_deanery' => '-1'],
            ['adct_page' => '101'],
            ['adct_period' => str_repeat('x', 100)],
        ] as $input) {
            try {
                new ListingSelection($input);
                self::fail('Malformed selection was accepted: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
