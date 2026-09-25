<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion;

final class InboundMailPolicy
{
    public function __construct(private EmailAddressSafety $addressSafety = new EmailAddressSafety())
    {
    }

    public function canSendConfirmation(
        InboundMessageRecord $message,
        ?string $replyToEmail = null
    ): bool {
        if (
            $message->status !== InboundMessageRecord::STATUS_RECEIVED
            || $message->isAutoReply
            || ! $this->addressSafety->isSafeConfirmationAddress($message->senderEmail)
        ) {
            return false;
        }

        return $this->addressSafety->isSafeConfirmationAddress($replyToEmail ?? $message->senderEmail);
    }

    public function requiresApprovalForDmarcFailure(
        bool $senderIsVerified,
        ?AuthenticationResults $authenticationResults
    ): bool {
        return $senderIsVerified && ($authenticationResults?->hasReportedDmarcFailure() ?? false);
    }

    public function canApplyInstantChange(
        bool $senderIsVerified,
        InboundMessageRecord $message
    ): bool {
        return $senderIsVerified
            && $message->status === InboundMessageRecord::STATUS_RECEIVED
            && ! $message->isAutoReply
            && $this->addressSafety->isSafeConfirmationAddress($message->senderEmail)
            && ! $this->requiresApprovalForDmarcFailure($senderIsVerified, $message->authResults);
    }
}
