<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Approval;

use ADCT\ParishIntake\Core\Approval\ApprovalReminderSettings;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApprovalReminderSettingsTest extends TestCase
{
    public function testDefaultsRemindAfterThreeDays(): void
    {
        $settings = new ApprovalReminderSettings();

        self::assertTrue($settings->enabled);
        self::assertSame(ApprovalReminderSettings::DEFAULT_DAYS, $settings->days);
        self::assertSame(3, $settings->days);
    }

    public function testSettingsCanBeSwitchedOff(): void
    {
        $settings = new ApprovalReminderSettings(false, 5);

        self::assertFalse($settings->enabled);
        self::assertSame(5, $settings->days);
    }

    #[DataProvider('outOfRangeDays')]
    public function testDaysOutsideTheSupportedRangeAreRejected(int $days): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ApprovalReminderSettings(true, $days);
    }

    public static function outOfRangeDays(): iterable
    {
        yield 'zero days' => [0];
        yield 'negative days' => [-1];
        yield 'beyond a year' => [366];
    }

    public function testTheUpperBoundIsAccepted(): void
    {
        self::assertSame(365, (new ApprovalReminderSettings(true, 365))->days);
    }
}