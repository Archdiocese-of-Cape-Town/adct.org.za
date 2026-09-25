<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Directory;

use ADCT\ParishIntake\Core\Directory\ContactService;
use ADCT\ParishIntake\Core\Directory\SenderLearningService;
use ADCT\ParishIntake\Core\Directory\SenderTrust;
use ADCT\ParishIntake\Core\Directory\SenderTrustStateMachine;
use ADCT\ParishIntake\Core\Parsing\ParseOutcome;
use ADCT\ParishIntake\Core\Parsing\ParseResult;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\ParishContactStoreInterface;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class SenderLearningServiceTest extends TestCase
{
    public function testSourceParishCreatesPendingSenderWhenTheAddressIsStillUnknown(): void
    {
        $store = new LearningParishContactStore();
        $service = new SenderLearningService(new ContactService($store, new FixedLearningClock()));

        $result = $service->learnUnknownSender('sender@example.test', 11, $this->outcome(null));

        self::assertNotNull($result);
        self::assertSame(SenderTrust::PENDING, $result->trust);
        self::assertSame([11], $result->parishIds);
        self::assertSame(SenderTrust::PENDING, $store->rows[1]['trust']);
        self::assertSame(0, $store->rows[1]['receives_reminders']);
    }

    public function testUniqueParserParishCreatesPendingSenderWhenNoSourceParishIsAvailable(): void
    {
        $store = new LearningParishContactStore();
        $service = new SenderLearningService(new ContactService($store, new FixedLearningClock()));

        $result = $service->learnUnknownSender('sender@example.test', null, $this->outcome(12));

        self::assertNotNull($result);
        self::assertSame([12], $result->parishIds);
        self::assertSame(SenderTrust::PENDING, $store->rows[1]['trust']);
    }

    public function testConflictingParishHintsDoNotCreatePendingContacts(): void
    {
        $store = new LearningParishContactStore();
        $service = new SenderLearningService(new ContactService($store, new FixedLearningClock()));

        $result = $service->learnUnknownSender('sender@example.test', 11, $this->outcome(12));

        self::assertNull($result);
        self::assertSame([], $store->rows);
    }

    private function outcome(?int $parishId): ParseOutcome
    {
        $result = new ParseResult();

        if ($parishId !== null) {
            $result->setField('parish_id', $parishId);
        }

        return new ParseOutcome(
            [$result],
            [],
            [],
            [],
            $result
        );
    }
}

final class LearningParishContactStore implements ParishContactStoreInterface
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
        $id = $this->nextId++;
        $this->rows[$id] = [
            'id' => $id,
            'parish_id' => $parishId,
            'email' => strtolower(trim($email)),
            'display_name' => $displayName,
            'role_label' => $roleLabel,
            'receives_reminders' => $receivesReminders ? 1 : 0,
            'trust' => $trust,
            'verified_at' => $verifiedAt,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }

    public function savePendingLink(
        int $parishId,
        string $email,
        string $displayName,
        string $roleLabel,
        bool $receivesReminders,
        string $timestamp
    ): int {
        $id = $this->nextId++;
        $this->rows[$id] = [
            'id' => $id,
            'parish_id' => $parishId,
            'email' => strtolower(trim($email)),
            'display_name' => $displayName,
            'role_label' => $roleLabel,
            'receives_reminders' => $receivesReminders ? 1 : 0,
            'trust' => SenderTrust::PENDING,
            'verified_at' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];

        return 1;
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
        return 0;
    }

    public function deleteLink(int $contactId, int $parishId): int
    {
        return 0;
    }

    public function setTrustForEmail(
        string $email,
        string $trust,
        ?string $verifiedAt,
        string $timestamp
    ): int {
        foreach ($this->rows as $id => $row) {
            if ($row['email'] === strtolower(trim($email))) {
                $this->rows[$id]['trust'] = $trust;
                $this->rows[$id]['verified_at'] = $verifiedAt;
                $this->rows[$id]['updated_at'] = $timestamp;
            }
        }

        return count($this->rows);
    }
}

final class FixedLearningClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-25 00:00:00', new DateTimeZone('Africa/Johannesburg'));
    }
}
