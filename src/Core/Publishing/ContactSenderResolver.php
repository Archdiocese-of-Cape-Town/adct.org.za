<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Publishing;

use ADCT\ParishIntake\Core\Directory\SenderLookup;
use ADCT\ParishIntake\Core\Directory\SenderLookupResult;
use ADCT\ParishIntake\Core\Directory\SenderTrust;
use ADCT\ParishIntake\Core\Ports\ParishContactStoreInterface;
use ADCT\ParishIntake\Core\Ports\PublicationAuthorityInterface;
use Throwable;

/**
 * Reads the acting address off a locked candidate row and asks the directory who
 * that address is, right now.
 *
 * Every failure resolves to UNKNOWN. SenderLookup::fromRows() throws on an invalid
 * or inconsistent trust state and EmailAddress::normalize() throws on a malformed
 * address, and neither exception should be allowed to escape the publication
 * transaction: the approver would meet a fatal error instead of a candidate in
 * their queue. UNKNOWN can never satisfy any authority, so a lookup that cannot be
 * completed is a refusal to publish rather than an error.
 *
 * This sits in Core rather than in the WordPress adapter so the port stays testable
 * without a database and CandidatePublisher gains no knowledge of the directory's
 * storage.
 */
final class ContactSenderResolver implements PublicationAuthorityInterface
{
    /**
     * A resolver holds no opinion on policy; it is handed to one as a collaborator.
     * The method is here to satisfy the port, and answering no means the class is
     * safe to pass anywhere a policy is expected without silently becoming one.
     */
    public function allowsContactChange(array $row, SenderLookupResult $sender): bool
    {
        return false;
    }

    /**
     * @param array<string, mixed> $row The locked candidate row.
     */
    public function resolveSender(array $row, ParishContactStoreInterface $contacts): SenderLookupResult
    {
        $email = self::actorEmail($row);

        if ($email === '') {
            return self::unknown();
        }

        try {
            return SenderLookup::fromRows($email, $contacts->findByEmail($email));
        } catch (Throwable) {
            return self::unknown();
        }
    }

    /**
     * The acting address for a candidate change. `approved_by` holds it: for a
     * change the router has already resolved who the sender is by the time the
     * candidate is published, so the row is the record.
     */
    private static function actorEmail(array $row): string
    {
        $value = $row['approved_by'] ?? null;

        if (! is_string($value)) {
            return '';
        }

        return trim($value);
    }

    private static function unknown(): SenderLookupResult
    {
        return new SenderLookupResult('', SenderTrust::UNKNOWN, []);
    }
}