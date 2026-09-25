<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Directory;

use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\ParishContactStoreInterface;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

final class ContactService
{
    private SenderTrustStateMachine $trustStateMachine;

    public function __construct(
        private ParishContactStoreInterface $contacts,
        private ClockInterface $clock,
        ?SenderTrustStateMachine $trustStateMachine = null
    ) {
        $this->trustStateMachine = $trustStateMachine ?? new SenderTrustStateMachine();
    }

    public function lookup(string $email): SenderLookupResult
    {
        $email = EmailAddress::normalize($email);
        $rows = $this->contacts->findByEmail($email);

        if ($rows === []) {
            return new SenderLookupResult($email, SenderTrust::UNKNOWN, []);
        }

        $parishIds = [];

        foreach ($rows as $row) {
            $parishId = (int) ($row['parish_id'] ?? 0);

            if ($parishId > 0) {
                $parishIds[] = $parishId;
            }
        }

        $parishIds = array_values(array_unique($parishIds));
        sort($parishIds, SORT_NUMERIC);

        return new SenderLookupResult($email, $this->trustOf($rows), $parishIds);
    }

    public function isBlocked(string $email): bool
    {
        return $this->lookup($email)->trust === SenderTrust::BLOCKED;
    }

    public function link(
        int $parishId,
        string $email,
        string $displayName = '',
        string $roleLabel = '',
        bool $receivesReminders = true
    ): SenderLookupResult {
        return $this->linkWithInitialTrust(
            $parishId,
            $email,
            $displayName,
            $roleLabel,
            $receivesReminders,
            SenderTrust::UNKNOWN,
            true
        );
    }

    public function linkOfficial(int $parishId, string $email): SenderLookupResult
    {
        return $this->linkWithInitialTrust(
            $parishId,
            $email,
            '',
            '',
            true,
            SenderTrust::VERIFIED,
            false
        );
    }

    public function updateLink(
        int $contactId,
        int $parishId,
        string $email,
        string $displayName,
        string $roleLabel,
        bool $receivesReminders
    ): SenderLookupResult {
        $this->assertPositiveIds($contactId, $parishId);
        $link = $this->contacts->findLink($contactId, $parishId);

        if ($link === null) {
            throw new DomainException('The parish contact link could not be found.');
        }

        $email = EmailAddress::normalize($email);
        $previousEmail = EmailAddress::normalize((string) ($link['email'] ?? ''));
        $displayName = $this->normalizeLabel($displayName);
        $roleLabel = $this->normalizeLabel($roleLabel);
        $timestamp = $this->timestamp();

        if ($previousEmail !== $email) {
            $previousRows = $this->requireLinks($previousEmail);
            $previousTrust = $this->trustOf($previousRows);

            if ($previousTrust === SenderTrust::BLOCKED && count($previousRows) === 1) {
                throw new DomainException('Unblock the address before changing its last parish link.');
            }
        }

        $existingRows = $this->contacts->findByEmail($email);
        $trust = $existingRows === [] ? SenderTrust::UNKNOWN : $this->trustOf($existingRows);
        $verifiedAt = $this->verifiedAt($existingRows, $trust, $timestamp);
        $existingParishLink = $this->findParishLink($existingRows, $parishId, $contactId);

        if ($existingParishLink !== null) {
            $this->contacts->saveLink(
                $parishId,
                $email,
                $displayName,
                $roleLabel,
                $receivesReminders,
                $trust,
                $verifiedAt,
                $timestamp
            );

            if ($this->contacts->deleteLink($contactId, $parishId) < 1) {
                throw new RuntimeException('The previous parish contact link could not be removed.');
            }
        } else {
            $updated = $this->contacts->updateLink(
                $contactId,
                $parishId,
                $email,
                $displayName,
                $roleLabel,
                $receivesReminders,
                $trust,
                $verifiedAt,
                $timestamp
            );

            if ($updated < 1 && $this->contacts->findLink($contactId, $parishId) === null) {
                throw new RuntimeException('The parish contact link could not be updated.');
            }
        }

        $this->synchronizeTrust($email, $trust, $verifiedAt, $timestamp);

        return $this->lookup($email);
    }

    public function removeLink(int $contactId, int $parishId): void
    {
        $this->assertPositiveIds($contactId, $parishId);
        $link = $this->contacts->findLink($contactId, $parishId);

        if ($link === null) {
            throw new DomainException('The parish contact link could not be found.');
        }

        $email = EmailAddress::normalize((string) ($link['email'] ?? ''));
        $addressLinks = $this->requireLinks($email);

        if (
            count($addressLinks) === 1
            && $this->trustOf($addressLinks) === SenderTrust::BLOCKED
        ) {
            throw new DomainException('Unblock the address before removing its last parish link.');
        }

        if ($this->contacts->deleteLink($contactId, $parishId) < 1) {
            throw new RuntimeException('The parish contact link could not be removed.');
        }
    }

    public function markPending(string $email): SenderLookupResult
    {
        return $this->changeTrust($email, SenderTrust::PENDING);
    }

    public function verify(string $email): SenderLookupResult
    {
        $email = EmailAddress::normalize($email);
        $rows = $this->requireLinks($email);
        $current = $this->trustOf($rows);

        if ($current === SenderTrust::UNKNOWN) {
            $this->trustStateMachine->transition(SenderTrust::UNKNOWN, SenderTrust::PENDING);
            $this->trustStateMachine->transition(SenderTrust::PENDING, SenderTrust::VERIFIED);
        } else {
            $this->trustStateMachine->transition($current, SenderTrust::VERIFIED);
        }

        $timestamp = $this->timestamp();
        $verifiedAt = $current === SenderTrust::VERIFIED
            ? $this->verifiedAt($rows, SenderTrust::VERIFIED, $timestamp)
            : $timestamp;
        $this->synchronizeTrust($email, SenderTrust::VERIFIED, $verifiedAt, $timestamp);

        return $this->lookup($email);
    }

    public function block(string $email): SenderLookupResult
    {
        return $this->changeTrust($email, SenderTrust::BLOCKED);
    }

    public function unblock(string $email): SenderLookupResult
    {
        $email = EmailAddress::normalize($email);
        $rows = $this->requireLinks($email);
        $current = $this->trustOf($rows);
        $next = $this->trustStateMachine->transition($current, SenderTrust::UNKNOWN, true);
        $timestamp = $this->timestamp();
        $this->synchronizeTrust($email, $next, null, $timestamp);

        return $this->lookup($email);
    }

    private function linkWithInitialTrust(
        int $parishId,
        string $email,
        string $displayName,
        string $roleLabel,
        bool $receivesReminders,
        string $initialTrust,
        bool $updateExistingLink
    ): SenderLookupResult {
        if ($parishId < 1) {
            throw new InvalidArgumentException('A parish must be selected for this contact.');
        }

        $email = EmailAddress::normalize($email);
        $displayName = $this->normalizeLabel($displayName);
        $roleLabel = $this->normalizeLabel($roleLabel);
        $timestamp = $this->timestamp();
        $rows = $this->contacts->findByEmail($email);
        $trust = $rows === [] ? $initialTrust : $this->trustOf($rows);
        $verifiedAt = $this->verifiedAt($rows, $trust, $timestamp);
        $existingParishLink = $this->findParishLink($rows, $parishId);

        if ($existingParishLink === null || $updateExistingLink) {
            $this->contacts->saveLink(
                $parishId,
                $email,
                $displayName,
                $roleLabel,
                $receivesReminders,
                $trust,
                $verifiedAt,
                $timestamp
            );
        }

        $this->synchronizeTrust($email, $trust, $verifiedAt, $timestamp);

        return $this->lookup($email);
    }

    private function changeTrust(string $email, string $next): SenderLookupResult
    {
        $email = EmailAddress::normalize($email);
        $rows = $this->requireLinks($email);
        $current = $this->trustOf($rows);
        $next = $this->trustStateMachine->transition($current, $next);
        $timestamp = $this->timestamp();
        $verifiedAt = $next === SenderTrust::VERIFIED
            ? $this->verifiedAt($rows, $next, $timestamp)
            : null;
        $this->synchronizeTrust($email, $next, $verifiedAt, $timestamp);

        return $this->lookup($email);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function requireLinks(string $email): array
    {
        $rows = $this->contacts->findByEmail($email);

        if ($rows === []) {
            throw new DomainException('The sender address has no parish links.');
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private function findParishLink(array $rows, int $parishId, ?int $exceptContactId = null): ?array
    {
        foreach ($rows as $row) {
            if (
                (int) ($row['parish_id'] ?? 0) === $parishId
                && ($exceptContactId === null || (int) ($row['id'] ?? 0) !== $exceptContactId)
            ) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function trustOf(array $rows): string
    {
        $states = [];

        foreach ($rows as $row) {
            $trust = (string) ($row['trust'] ?? '');

            if (! SenderTrust::isValid($trust)) {
                throw new LogicException('A parish contact has an invalid trust state.');
            }

            $states[$trust] = true;
        }

        if (count($states) !== 1) {
            throw new LogicException('A sender address has inconsistent trust states across parish links.');
        }

        return (string) array_key_first($states);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function verifiedAt(array $rows, string $trust, string $fallback): ?string
    {
        if ($trust !== SenderTrust::VERIFIED) {
            return null;
        }

        $timestamps = [];

        foreach ($rows as $row) {
            $verifiedAt = $row['verified_at'] ?? null;

            if (is_string($verifiedAt) && $verifiedAt !== '') {
                $timestamps[] = $verifiedAt;
            }
        }

        if ($timestamps === []) {
            return $fallback;
        }

        sort($timestamps, SORT_STRING);

        return $timestamps[count($timestamps) - 1];
    }

    private function synchronizeTrust(
        string $email,
        string $trust,
        ?string $verifiedAt,
        string $timestamp
    ): void {
        $this->contacts->setTrustForEmail($email, $trust, $verifiedAt, $timestamp);
        $rows = $this->requireLinks($email);

        if ($this->trustOf($rows) !== $trust) {
            throw new LogicException('The sender trust state could not be synchronized across parish links.');
        }
    }

    private function normalizeLabel(string $label): string
    {
        $normalized = trim($label);
        $matches = preg_match_all('/[\s\S]/u', $normalized, $characters);

        if ($matches === false) {
            throw new InvalidArgumentException('Contact names and role labels must be valid UTF-8 text.');
        }

        if ($matches > 191) {
            throw new InvalidArgumentException('Contact names and role labels must be 191 characters or fewer.');
        }

        return $normalized;
    }

    private function assertPositiveIds(int $contactId, int $parishId): void
    {
        if ($contactId < 1 || $parishId < 1) {
            throw new InvalidArgumentException('A parish and contact ID are required.');
        }
    }

    private function timestamp(): string
    {
        return $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }
}
