<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Parsing;

use ADCT\ParishIntake\Tests\Support\FixtureComparator;
use PHPUnit\Framework\TestCase;

final class FixtureComparatorTest extends TestCase
{
    public function testComparesOnlyExpectedFields(): void
    {
        $checks = FixtureComparator::compare(
            ['classification' => 'event'],
            ['classification' => 'event', 'unexpected_field' => 'not asserted']
        );

        self::assertSame(['classification'], array_keys($checks));
        self::assertTrue($checks['classification']['matches']);
    }

    public function testParentKnownFailureCoversNestedListMismatchPaths(): void
    {
        self::assertSame(
            '#40',
            FixtureComparator::knownIssueForPath('events[1].title', ['events' => '#40'])
        );
        self::assertSame(
            '#74',
            FixtureComparator::knownIssueForPath(
                'fields.attachment_names[count]',
                ['fields.attachment_names' => '#74']
            )
        );
    }
}
