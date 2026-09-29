<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Directory;

use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\ParseOutcome;
use ADCT\ParishIntake\Core\Ports\DirectorySnapshotProviderInterface;

final class SenderParishSuggester
{
    private DirectoryLookup $directory;

    public function __construct(private DirectorySnapshotProviderInterface $snapshots)
    {
        $this->directory = new DirectoryLookup($snapshots);
    }

    /** @return array{0: ?int, 1: ?string} */
    public function suggest(Message $message, ParseOutcome $outcome): array
    {
        $signature = $this->directory->matchParish($message->getSignatureText())->match;
        if ($signature !== null) {
            return [$signature->parishId, 'signature'];
        }

        $candidateIds = [];
        foreach ($outcome->getCandidates() as $candidate) {
            $id = $candidate->getField('parish_id');
            if (is_int($id) && $id > 0) {
                $candidateIds[$id] = true;
            }
        }
        if (count($candidateIds) === 1) {
            return [(int) array_key_first($candidateIds), 'parser'];
        }
        if (count($candidateIds) > 1) {
            return [null, null];
        }

        $body = $this->directory->matchParish($message->getBody())->match;
        if ($body !== null) {
            return [$body->parishId, 'body'];
        }

        $domain = strtolower(substr(strrchr($message->getSenderEmail(), '@') ?: '', 1));
        if ($domain === '') {
            return [null, null];
        }

        $parishIds = [];
        foreach ($this->snapshots->getSnapshot()->contacts as $contact) {
            $email = strtolower((string) ($contact['email'] ?? ''));
            if (
                ($contact['trust'] ?? '') === SenderTrust::VERIFIED
                && str_ends_with($email, '@' . $domain)
                && (int) ($contact['parish_id'] ?? 0) > 0
            ) {
                $parishIds[(int) $contact['parish_id']] = true;
            }
        }

        return count($parishIds) === 1
            ? [(int) array_key_first($parishIds), 'domain']
            : [null, null];
    }
}
