<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Events;

use ADCT\ParishIntake\Core\Events\RRulePresetMapper;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RRulePresetMapperTest extends TestCase
{
    public function testMapsFirstFridayMonthlyPresetToRRuleAndBack(): void
    {
        $mapper = new RRulePresetMapper();
        $rule = $mapper->toRRule('monthly_ordinal', 'FR', '1');

        self::assertSame('FREQ=MONTHLY;BYDAY=1FR', $rule);
        self::assertSame([
            'preset' => 'monthly_ordinal',
            'weekday' => 'FR',
            'ordinal' => '1',
            'month_day' => '1',
            'custom_rule' => '',
        ], $mapper->fromRRule($rule));
    }

    public function testMapsWeeklyAndMonthlyDayPresets(): void
    {
        $mapper = new RRulePresetMapper();

        self::assertSame('FREQ=WEEKLY;BYDAY=WE', $mapper->toRRule('weekly', 'WE'));
        self::assertSame([
            'preset' => 'weekly',
            'weekday' => 'WE',
            'ordinal' => '1',
            'month_day' => '1',
            'custom_rule' => '',
        ], $mapper->fromRRule('FREQ=WEEKLY;BYDAY=WE'));

        self::assertSame('FREQ=MONTHLY;BYMONTHDAY=23', $mapper->toRRule('monthly_day', '', '', '23'));
        self::assertSame([
            'preset' => 'monthly_day',
            'weekday' => 'MO',
            'ordinal' => '1',
            'month_day' => '23',
            'custom_rule' => '',
        ], $mapper->fromRRule('FREQ=MONTHLY;BYMONTHDAY=23'));
    }

    public function testNoneAndCustomRulesRoundTrip(): void
    {
        $mapper = new RRulePresetMapper();

        self::assertNull($mapper->toRRule('none'));
        self::assertSame('none', $mapper->fromRRule(null)['preset']);
        self::assertSame('FREQ=YEARLY;COUNT=2', $mapper->toRRule('custom', '', '', '', 'FREQ=YEARLY;COUNT=2'));
        self::assertSame([
            'preset' => 'custom',
            'weekday' => 'MO',
            'ordinal' => '1',
            'month_day' => '1',
            'custom_rule' => 'FREQ=YEARLY;COUNT=2',
        ], $mapper->fromRRule('FREQ=YEARLY;COUNT=2'));
    }

    public function testRejectsInvalidPresetValues(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new RRulePresetMapper())->toRRule('monthly_ordinal', 'FR', '5');
    }
}
