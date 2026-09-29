<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Directory;

use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\ParseOutcome;

final class SenderLearningService
{
    public function __construct(
        private ContactService $contacts,
        private SenderParishSuggester $parishSuggester
    ) {
    }

    public function learnUnknownSender(
        Message $message,
        ParseOutcome $outcome
    ): ?SenderLookupResult {
        $email = EmailAddress::normalize($message->getSenderEmail());
        $lookup = $this->contacts->lookup($email);

        if ($lookup->trust !== SenderTrust::UNKNOWN || $lookup->parishIds !== []) {
            return null;
        }

        [$suggestedParishId, $source] = $this->parishSuggester->suggest($message, $outcome);

        return $this->contacts->learnPending($email, $suggestedParishId, $source);
    }
}
