<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Approval;

use ADCT\ParishIntake\Core\Approval\ApprovalSenderIdentity;
use ADCT\ParishIntake\Core\Ingestion\RawMessageInspector;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ApprovalSenderIdentityTest extends TestCase
{
    public function testValidAddressesAreNormalisedAndNamesAreShownBesideThem(): void
    {
        self::assertSame(
            'parish.office@example.test (Parish Office)',
            ApprovalSenderIdentity::format('  Parish.Office@Example.TEST ', ' Parish Office ')
        );
    }

    public function testMissingValuesAndUnparseableAddressesRemainVisible(): void
    {
        self::assertSame('Unknown (no name supplied)', ApprovalSenderIdentity::format(null, null));
        self::assertSame(
            'Parish Office <not-an-address> (no name supplied)',
            ApprovalSenderIdentity::format(' Parish Office <not-an-address> ', " \r\n\t")
        );
    }

    public function testAnAddressOnlyHeaderDoesNotMasqueradeAsADisplayName(): void
    {
        $message = (new RawMessageInspector())->inspect(
            "From: office@example.test\r\nSubject: Example\r\n\r\nAn invented notice.",
            new DateTimeImmutable('2026-10-01T00:00:00+00:00')
        );
        self::assertSame('office@example.test', $message->senderEmail);
        self::assertSame(
            'office@example.test (no name supplied)',
            ApprovalSenderIdentity::format($message->senderEmail, $message->senderName)
        );
    }

    public function testAnAddressRepeatedAsTheNameIsNotASeparateDisplayName(): void
    {
        self::assertSame(
            'parish.office@example.test (no name supplied)',
            ApprovalSenderIdentity::format(' Parish.Office@Example.TEST ', ' parish.office@example.test ')
        );
    }

    public function testANameCannotInjectAnotherLineAndMarkupIsLeftForTheOutputContext(): void
    {
        self::assertSame(
            'parish@example.test (<b>Parish Office</b>)',
            ApprovalSenderIdentity::format(
                'parish@example.test',
                "<b>Parish Office</b>\r\nSubmitted by: intruder@example.test"
            )
        );
    }

    public function testLongNamesRemainBoundedWithoutBreakingUtf8(): void
    {
        $identity = ApprovalSenderIdentity::format(
            'parish@example.test',
            str_repeat('x', 999) . 'é' . str_repeat('y', 50)
        );

        self::assertSame(1, preg_match('//u', $identity));
        self::assertLessThanOrEqual(strlen('parish@example.test ()') + 1003, strlen($identity));
        self::assertStringEndsWith('...)', $identity);
        self::assertStringNotContainsString('y', $identity);
    }
}
