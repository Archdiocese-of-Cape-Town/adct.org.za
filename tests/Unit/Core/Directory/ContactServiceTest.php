<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Directory;

use ADCT\ParishIntake\Core\Directory\ContactService;
use ADCT\ParishIntake\Core\Directory\EmailAddress;
use ADCT\ParishIntake\Core\Directory\SenderTrust;
use ADCT\ParishIntake\Core\Directory\SenderTrustStateMachine;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\ParishContactStoreInterface;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ContactServiceTest extends TestCase
{
    public function testEmailAddressesAreTrimmedLowercaseAndValidated(): void
    {
        self::assertSame('sender@example.test', EmailAddress::normalize(' Sender@Example.Test '));

        foreach (['', 'not-an-email', 'sender@', 'sender@example.test' . str_repeat('x', 190)] as $email) {
            try {
                EmailAddress::normalize($email);
                self::fail('An invalid email address was accepted: ' . $email);
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testTrustStateMachineAllowsOnlySpecifiedTransitions(): void
    {
        $machine = new SenderTrustStateMachine();
        self::assertSame(
            SenderTrust::PENDING,
            $machine->transition(SenderTrust::UNKNOWN, SenderTrust::PENDING)
        );
        self::assertSame(
            SenderTrust::VERIFIED,
            $machine->transition(SenderTrust::PENDING, SenderTrust::VERIFIED)
        );

        foreach ([SenderTrust::UNKNOWN, SenderTrust::PENDING, SenderTrust::VERIFIED] as $state) {
            self::assertSame(SenderTrust::BLOCKED, $machine->transition($state, SenderTrust::BLOCKED));
        }

        self::assertSame(
            SenderTrust::UNKNOWN,
            $machine->transition(SenderTrust::BLOCKED, SenderTrust::UNKNOWN, true)
        );

        foreach ([
            [SenderTrust::UNKNOWN, SenderTrust::VERIFIED, false],
            [SenderTrust::PENDING, SenderTrust::UNKNOWN, false],
            [SenderTrust::VERIFIED, SenderTrust::PENDING, false],
            [SenderTrust::BLOCKED, SenderTrust::UNKNOWN, false],
            [SenderTrust::BLOCKED, SenderTrust::VERIFIED, true],
        ] as [$from, $to, $explicitAdminUnblock]) {
            try {
                $machine->transition($from, $to, $explicitAdminUnblock);
                self::fail(sprintf('The transition %s -> %s was accepted.', $from, $to));
            } catch (DomainException) {
                self::assertTrue(true);
            }
        }
    }

    public function testLookupReturnsEveryParishLinkedToOneNormalisedAddress(): void
    {
        $store = new FakeParishContactStore();
        $service = new ContactService($store, new FixedContactClock());

        $service->link(11, 'Sender@Example.Test', 'Office', 'Secretary', true);
        $service->link(12, ' sender@example.test ', 'Office', 'Secretary', true);

        $result = $service->lookup('SENDER@EXAMPLE.TEST');

        self::assertSame('sender@example.test', $result->email);
        self::assertSame(SenderTrust::UNKNOWN, $result->trust);
        self::assertSame([11, 12], $result->parishIds);
        self::assertFalse($service->isBlocked('sender@example.test'));
    }

    public function testBlockingAnAddressUpdatesAllParishLinksAndVerificationDoesNotSkipStates(): void
    {
        $store = new FakeParishContactStore();
        $service = new ContactService($store, new FixedContactClock());
        $email = 'sender@example.test';
        $service->link(11, $email, 'Office', 'Secretary', true);
        $service->link(12, $email, 'Office', 'Secretary', true);

        $service->markPending($email);
        $verified = $service->verify($email);

        self::assertSame(SenderTrust::VERIFIED, $verified->trust);
        self::assertSame('2026-09-24 22:00:00', $store->rows[1]['verified_at']);
        self::assertSame('2026-09-24 22:00:00', $store->rows[2]['verified_at']);

        $blocked = $service->block($email);

        self::assertSame(SenderTrust::BLOCKED, $blocked->trust);
        self::assertTrue($service->isBlocked($email));
        self::assertSame([SenderTrust::BLOCKED, SenderTrust::BLOCKED], array_column($store->rows, 'trust'));
        self::assertSame([null, null], array_column($store->rows, 'verified_at'));

        $service->unblock($email);
        self::assertSame(SenderTrust::UNKNOWN, $service->lookup($email)->trust);
        self::assertFalse($service->isBlocked($email));
    }

    public function testLinkingSameAddressTwiceToOneParishUpdatesInsteadOfDuplicating(): void
    {
        $store = new FakeParishContactStore();
        $service = new ContactService($store, new FixedContactClock());

        $service->link(11, 'sender@example.test', 'First name', 'Secretary', true);
        $service->link(11, ' SENDER@example.test ', 'Updated name', 'Office', false);

        self::assertCount(1, $store->rows);
        self::assertSame('Updated name', $store->rows[1]['display_name']);
        self::assertSame('Office', $store->rows[1]['role_label']);
        self::assertSame(0, $store->rows[1]['receives_reminders']);
    }

    public function testNewLinksInheritTheAddressTrustAndOfficialImportsOnlyVerifyNewAddresses(): void
    {
        $store = new FakeParishContactStore();
        $service = new ContactService($store, new FixedContactClock());

        $service->link(11, 'sender@example.test', 'Office', 'Secretary', true);
        $service->markPending('sender@example.test');
        $service->linkOfficial(12, 'sender@example.test');

        self::assertSame(
            [SenderTrust::PENDING, SenderTrust::PENDING],
            array_column($store->rows, 'trust')
        );

        $service->linkOfficial(13, 'new-office@example.test');

        self::assertSame(SenderTrust::VERIFIED, $service->lookup('new-office@example.test')->trust);
    }

    public function testChangingALinkEmailUsesExistingAddressTrustAndDeduplicatesParishLinks(): void
    {
        $store = new FakeParishContactStore();
        $service = new ContactService($store, new FixedContactClock());
        $service->link(11, 'old@example.test', 'Office', 'Secretary', true);
        $service->link(11, 'new@example.test', 'Existing', 'Office', false);
        $service->markPending('new@example.test');

        $link = $store->findByEmail('old@example.test')[0];
        $service->updateLink(
            (int) $link['id'],
            11,
            'new@example.test',
            'Updated',
            'Secretary',
            true
        );

        self::assertSame([], $store->findByEmail('old@example.test'));
        self::assertCount(1, $store->findByEmail('new@example.test'));
        self::assertSame(SenderTrust::PENDING, $store->findByEmail('new@example.test')[0]['trust']);
        self::assertSame('Updated', $store->findByEmail('new@example.test')[0]['display_name']);
    }

    public function testBlockedAddressCannotLoseItsLastLinkWithoutAnExplicitUnblock(): void
    {
        $store = new FakeParishContactStore();
        $service = new ContactService($store, new FixedContactClock());
        $service->link(11, 'blocked@example.test', 'Office', 'Secretary', true);
        $service->block('blocked@example.test');

        foreach (['remove', 'change-email'] as $operation) {
            try {
                if ($operation === 'remove') {
                    $service->removeLink(1, 11);
                } else {
                    $service->updateLink(1, 11, 'new@example.test', 'Office', 'Secretary', true);
                }

                self::fail('A blocked address lost its only parish link without being unblocked.');
            } catch (DomainException) {
                self::assertSame(SenderTrust::BLOCKED, $service->lookup('blocked@example.test')->trust);
            }
        }

        $service->unblock('blocked@example.test');
        $service->updateLink(1, 11, 'new@example.test', 'Office', 'Secretary', true);

        self::assertSame([], $store->findByEmail('blocked@example.test'));
        self::assertSame([11], $service->lookup('new@example.test')->parishIds);
    }
}

final class FakeParishContactStore implements ParishContactStoreInterface
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $rows = [];

    private int $nextId = 1;

    public function findByEmail(string $email): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (array $row): bool => $row['email'] === $email
        ));
    }

    public function findLink(int $contactId, int $parishId): ?array
    {
        $row = $this->rows[$contactId] ?? null;

        return is_array($row) && (int) $row['parish_id'] === $parishId ? $row : null;
    }

    public function findForParish(int $parishId): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (array $row): bool => (int) $row['parish_id'] === $parishId
        ));
    }

    public function saveLink(
        int $parishId,
        string $email,
        string $displayName,
        string $roleLabel,
        bool $receivesReminders,
        string $trust,
        ?string $verifiedAt,
        string $timestamp
    ): void {
        foreach ($this->rows as $id => $row) {
            if ((int) $row['parish_id'] === $parishId && $row['email'] === $email) {
                $this->rows[$id] = array_merge($row, [
                    'display_name' => $displayName,
                    'role_label' => $roleLabel,
                    'receives_reminders' => $receivesReminders ? 1 : 0,
                    'trust' => $trust,
                    'verified_at' => $verifiedAt,
                    'updated_at' => $timestamp,
                ]);

                return;
            }
        }

        $id = $this->nextId++;
        $this->rows[$id] = [
            'id' => $id,
            'parish_id' => $parishId,
            'email' => $email,
            'display_name' => $displayName,
            'role_label' => $roleLabel,
            'receives_reminders' => $receivesReminders ? 1 : 0,
            'trust' => $trust,
            'verified_at' => $verifiedAt,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }

    public function updateLink(
        int $contactId,
        int $parishId,
        string $email,
        string $displayName,
        string $roleLabel,
        bool $receivesReminders,
        string $trust,
        ?string $verifiedAt,
        string $timestamp
    ): int {
        $row = $this->findLink($contactId, $parishId);

        if ($row === null) {
            return 0;
        }

        foreach ($this->rows as $id => $other) {
            if ($id !== $contactId && (int) $other['parish_id'] === $parishId && $other['email'] === $email) {
                throw new DomainException('The parish already has a link for this email address.');
            }
        }

        $this->rows[$contactId] = array_merge($row, [
            'email' => $email,
            'display_name' => $displayName,
            'role_label' => $roleLabel,
            'receives_reminders' => $receivesReminders ? 1 : 0,
            'trust' => $trust,
            'verified_at' => $verifiedAt,
            'updated_at' => $timestamp,
        ]);

        return 1;
    }

    public function deleteLink(int $contactId, int $parishId): int
    {
        if ($this->findLink($contactId, $parishId) === null) {
            return 0;
        }

        unset($this->rows[$contactId]);

        return 1;
    }

    public function setTrustForEmail(
        string $email,
        string $trust,
        ?string $verifiedAt,
        string $timestamp
    ): int {
        $updated = 0;

        foreach ($this->rows as $id => $row) {
            if ($row['email'] === $email) {
                $this->rows[$id]['trust'] = $trust;
                $this->rows[$id]['verified_at'] = $verifiedAt;
                $this->rows[$id]['updated_at'] = $timestamp;
                ++$updated;
            }
        }

        return $updated;
    }
}

final class FixedContactClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-25 00:00:00', new DateTimeZone('Africa/Johannesburg'));
    }
}
