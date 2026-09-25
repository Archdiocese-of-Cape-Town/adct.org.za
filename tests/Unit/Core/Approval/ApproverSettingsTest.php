<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Approval;

use ADCT\ParishIntake\Core\Approval\ApproverSettings;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ApproverSettingsTest extends TestCase
{
    public function testValidSettingsNormalizeEmailAndLabel(): void
    {
        $settings = new ApproverSettings(
            '  APPROVER@EXAMPLE.TEST ',
            '  Dean  ',
            ApproverSettings::NOTIFY_DIGEST,
            true,
            true
        );

        self::assertSame('approver@example.test', $settings->email);
        self::assertSame('Dean', $settings->label);
        self::assertSame(ApproverSettings::NOTIFY_DIGEST, $settings->notifyMode);
        self::assertTrue($settings->remindersEnabled);
        self::assertTrue($settings->active);
    }

    public function testInvalidApprovalEmailIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The email address is not valid.');

        new ApproverSettings('not-an-email', 'Dean', ApproverSettings::NOTIFY_EACH, true, true);
    }

    public function testInvalidNotificationModeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The notification mode must be each or digest.');

        new ApproverSettings('approver@example.test', 'Dean', 'weekly', true, true);
    }

    public function testEmptyApproverLabelIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An approver label is required.');

        new ApproverSettings('approver@example.test', '', ApproverSettings::NOTIFY_EACH, true, true);
    }
}
