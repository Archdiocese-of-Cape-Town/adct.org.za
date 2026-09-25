<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Events;

use ADCT\ParishIntake\Core\Events\RRuleValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RRuleValidatorTest extends TestCase
{
    #[DataProvider('validRules')]
    public function testAcceptsTheSupportedRRuleSubset(string $rule, bool $allDay = false): void
    {
        $result = (new RRuleValidator())->validate($rule, $allDay);

        self::assertTrue($result->isValid(), implode(' ', $result->errors));
        self::assertSame([], $result->errors);
    }

    public static function validRules(): iterable
    {
        yield 'daily' => ['FREQ=DAILY'];
        yield 'weekly weekday' => ['FREQ=WEEKLY;BYDAY=FR'];
        yield 'monthly first Friday' => ['FREQ=MONTHLY;BYDAY=1FR'];
        yield 'explicit positive ordinal' => ['FREQ=MONTHLY;BYDAY=+1FR'];
        yield 'monthly last Sunday' => ['FREQ=MONTHLY;BYDAY=-1SU'];
        yield 'monthly day and month filter' => ['FREQ=MONTHLY;BYMONTHDAY=12;BYMONTH=3,9'];
        yield 'yearly interval and count' => ['FREQ=YEARLY;INTERVAL=2;COUNT=6'];
        yield 'until date time' => ['FREQ=MONTHLY;UNTIL=20261231T235900'];
        yield 'all day until date' => ['FREQ=YEARLY;UNTIL=20271231', true];
        yield 'set position' => ['FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1'];
        yield 'prefixed rule' => ['RRULE:FREQ=WEEKLY;BYDAY=MO'];
        yield 'no recurrence' => [''];
    }

    #[DataProvider('invalidRules')]
    public function testRejectsUnsupportedOrMalformedRRules(string $rule): void
    {
        $result = (new RRuleValidator())->validate($rule);

        self::assertFalse($result->isValid(), $rule . ' should be invalid.');
        self::assertNotSame([], $result->errors);
    }

    public static function invalidRules(): iterable
    {
        yield 'missing frequency' => ['BYDAY=FR'];
        yield 'unsupported frequency' => ['FREQ=HOURLY'];
        yield 'unknown part' => ['FREQ=MONTHLY;BYWEEKNO=2'];
        yield 'duplicate part' => ['FREQ=MONTHLY;FREQ=YEARLY'];
        yield 'zero interval' => ['FREQ=WEEKLY;INTERVAL=0'];
        yield 'zero count' => ['FREQ=DAILY;COUNT=0'];
        yield 'until and count' => ['FREQ=MONTHLY;COUNT=5;UNTIL=20261231T235900'];
        yield 'zero ordinal' => ['FREQ=MONTHLY;BYDAY=0FR'];
        yield 'ordinal on weekly rule' => ['FREQ=WEEKLY;BYDAY=1FR'];
        yield 'zero month day' => ['FREQ=MONTHLY;BYMONTHDAY=0'];
        yield 'month out of range' => ['FREQ=YEARLY;BYMONTH=13'];
        yield 'set position without another by part' => ['FREQ=MONTHLY;BYSETPOS=1'];
        yield 'invalid until date' => ['FREQ=MONTHLY;UNTIL=20260230'];
    }

    public function testAllDayRulesRejectDateTimeUntilValues(): void
    {
        $result = (new RRuleValidator())->validate(
            'FREQ=YEARLY;UNTIL=20271231T235959',
            true
        );

        self::assertFalse($result->isValid());
        self::assertContains('UNTIL must be a date for an all-day event.', $result->errors);
    }

    public function testTimedRulesRejectDateOnlyUntilValues(): void
    {
        $result = (new RRuleValidator())->validate(
            'FREQ=YEARLY;UNTIL=20271231',
            false
        );

        self::assertFalse($result->isValid());
        self::assertContains('UNTIL must include a time for a timed event.', $result->errors);
    }
}
