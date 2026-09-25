<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Ingestion;

use ADCT\ParishIntake\Core\Ingestion\AuthenticationResult;
use ADCT\ParishIntake\Core\Ingestion\AuthenticationResults;
use ADCT\ParishIntake\Core\Ingestion\InboundMailPolicy;
use ADCT\ParishIntake\Core\Ingestion\InboundMessageRecord;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class InboundMailPolicyTest extends TestCase
{
    public function testConfirmationEligibilityBlocksAutomatedMailAndUnsafeAddresses(): void
    {
        $policy = new InboundMailPolicy();

        self::assertFalse($policy->canSendConfirmation($this->message('office@example.test', true)));
        self::assertFalse($policy->canSendConfirmation($this->message('no-reply@example.test')));
        self::assertFalse($policy->canSendConfirmation($this->message('do-not-reply@example.test')));
        self::assertFalse($policy->canSendConfirmation($this->message(null)));
        self::assertFalse($policy->canSendConfirmation($this->message('not-an-email')));
        self::assertFalse($policy->canSendConfirmation(
            $this->message('office@example.test', status: InboundMessageRecord::STATUS_SKIPPED)
        ));
        self::assertFalse($policy->canSendConfirmation(
            $this->message('office@example.test'),
            'mailer-daemon@example.test'
        ));
        self::assertFalse($policy->canSendConfirmation(
            $this->message('office@example.test'),
            'not-an-email'
        ));
    }

    public function testConfirmationEligibilityAllowsAnOrdinarySafeParishAddress(): void
    {
        $policy = new InboundMailPolicy();

        self::assertTrue($policy->canSendConfirmation($this->message('office@example.test')));
        self::assertTrue($policy->canSendConfirmation(
            $this->message('office@example.test'),
            'events@example.test'
        ));
    }

    public function testInstantChangesRequireVerifiedSenderAndNoAutomatedMailOrReportedDmarcFailure(): void
    {
        $policy = new InboundMailPolicy();
        $ordinary = $this->message('office@example.test');
        $automated = $this->message('office@example.test', true);
        $skipped = $this->message('office@example.test', status: InboundMessageRecord::STATUS_SKIPPED);
        $dmarcFailure = $this->message(
            'office@example.test',
            authenticationResults: new AuthenticationResults([
                new AuthenticationResult('dmarc', 'fail', 'external.example.test', false),
            ])
        );

        self::assertTrue($policy->canApplyInstantChange(true, $ordinary));
        self::assertFalse($policy->canApplyInstantChange(false, $ordinary));
        self::assertFalse($policy->canApplyInstantChange(true, $automated));
        self::assertFalse($policy->canApplyInstantChange(true, $skipped));
        self::assertFalse($policy->canApplyInstantChange(true, $dmarcFailure));
        self::assertTrue($policy->requiresApprovalForDmarcFailure(true, $dmarcFailure->authResults));
        self::assertFalse($policy->requiresApprovalForDmarcFailure(false, $dmarcFailure->authResults));
    }

    public function testUntrustedAuthenticationPassDoesNotGrantSenderTrust(): void
    {
        $policy = new InboundMailPolicy();
        $untrustedPass = new AuthenticationResults([
            new AuthenticationResult('spf', 'pass', 'external.example.test', false),
            new AuthenticationResult('dkim', 'pass', 'external.example.test', false),
            new AuthenticationResult('dmarc', 'pass', 'external.example.test', false),
        ]);
        $message = $this->message('office@example.test', authenticationResults: $untrustedPass);

        self::assertFalse($untrustedPass->hasTrustedPass());
        self::assertFalse($policy->canApplyInstantChange(false, $message));
        self::assertTrue($policy->canApplyInstantChange(true, $message));
    }

    private function message(
        ?string $senderEmail,
        bool $isAutoReply = false,
        ?AuthenticationResults $authenticationResults = null,
        string $status = InboundMessageRecord::STATUS_RECEIVED
    ): InboundMessageRecord {
        return new InboundMessageRecord(
            17,
            '<policy-test@example.test>',
            null,
            $senderEmail,
            null,
            'Synthetic policy test',
            new DateTimeImmutable('2026-09-25T04:00:00+00:00'),
            null,
            [],
            $status,
            null,
            $isAutoReply,
            $authenticationResults
        );
    }
}
