<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Directory;

use ADCT\ParishIntake\Core\Directory\ContactService;
use ADCT\ParishIntake\Core\Directory\DirectorySnapshot;
use ADCT\ParishIntake\Core\Directory\SenderParishSuggester;
use ADCT\ParishIntake\Core\Directory\SenderLearningService;
use ADCT\ParishIntake\Core\Directory\SenderTrust;
use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\ParseOutcome;
use ADCT\ParishIntake\Core\Parsing\ParseResult;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\DirectorySnapshotProviderInterface;
use ADCT\ParishIntake\Core\Ports\ParishContactStoreInterface;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class SenderLearningServiceTest extends TestCase
{
    public function testSignatureGuessCreatesOnlyAnUnlinkedPendingSuggestionAndRetryIsIdempotent(): void
    {
        $store = new LearningParishContactStore();
        $service = $this->service($store);
        $message = new Message(
            'email',
            '1',
            'Sender@example.test',
            'Sample Sender',
            'Parish event',
            'An event at St Alpha Parish',
            [],
            null,
            [],
            '',
            'St Alpha Parish office'
        );

        $result = $service->learnUnknownSender($message, $this->outcome([12]));
        $retry = $service->learnUnknownSender(
            new Message('email', '2', ' sender@EXAMPLE.test ', '', '', ''),
            $this->outcome([])
        );

        self::assertNotNull($result);
        self::assertSame(SenderTrust::PENDING, $result->trust);
        self::assertSame([], $result->parishIds);
        self::assertNull($retry);
        self::assertCount(1, $store->rows);
        self::assertSame(0, $store->rows[1]['parish_id']);
        self::assertSame(11, $store->rows[1]['suggested_parish_id']);
        self::assertSame('signature', $store->rows[1]['suggestion_source']);
        self::assertNull($store->rows[1]['verified_at']);
        self::assertSame(0, $store->rows[1]['receives_reminders']);
    }

    public function testUnknownSenderWithoutAUniqueGuessStillCreatesAnUnlinkedPendingContact(): void
    {
        $store = new LearningParishContactStore();
        $service = $this->service($store);

        $result = $service->learnUnknownSender(
            new Message('email', '1', 'sender@example.test', '', '', 'Upcoming events'),
            $this->outcome([11, 12])
        );

        self::assertNotNull($result);
        self::assertSame([], $result->parishIds);
        self::assertSame(0, $store->rows[1]['parish_id']);
        self::assertNull($store->rows[1]['suggested_parish_id']);
        self::assertNull($store->rows[1]['suggestion_source']);
        self::assertSame(SenderTrust::PENDING, $store->rows[1]['trust']);
        self::assertNull($store->rows[1]['verified_at']);
    }

    public function testVerifiedDomainIsStoredOnlyAsSuggestion(): void
    {
        $store = new LearningParishContactStore();
        $service = $this->service($store, new DirectorySnapshot(
            [],
            [],
            [['parish_id' => 11, 'email' => 'verified@unique.test', 'trust' => SenderTrust::VERIFIED]]
        ));

        $result = $service->learnUnknownSender(
            new Message('email', '1', 'sender@unique.test', '', '', ''),
            $this->outcome([])
        );

        self::assertNotNull($result);
        self::assertSame(SenderTrust::PENDING, $result->trust);
        self::assertSame([], $result->parishIds);
        self::assertSame(0, $store->rows[1]['parish_id']);
        self::assertSame(11, $store->rows[1]['suggested_parish_id']);
        self::assertSame('domain', $store->rows[1]['suggestion_source']);
        self::assertNull($store->rows[1]['verified_at']);
    }

    private function service(
        LearningParishContactStore $store,
        ?DirectorySnapshot $snapshot = null
    ): SenderLearningService {
        $snapshot ??= new DirectorySnapshot(
            [
                ['id' => 11, 'name' => 'St Alpha Parish', 'status' => 'active'],
                ['id' => 12, 'name' => 'St Beta Parish', 'status' => 'active'],
            ],
            [],
            []
        );
        $provider = new class ($snapshot) implements DirectorySnapshotProviderInterface {
            public function __construct(private DirectorySnapshot $snapshot)
            {
            }

            public function getSnapshot(): DirectorySnapshot
            {
                return $this->snapshot;
            }
        };

        return new SenderLearningService(
            new ContactService($store, new FixedLearningClock()),
            new SenderParishSuggester($provider)
        );
    }

    /** @param list<int> $parishIds */
    private function outcome(array $parishIds): ParseOutcome
    {
        $candidates = [];
        foreach ($parishIds as $parishId) {
            $candidate = new ParseResult();
            $candidate->setField('parish_id', $parishId);
            $candidates[] = $candidate;
        }

        return new ParseOutcome(
            $candidates,
            [],
            [],
            [],
            $candidates[0] ?? new ParseResult()
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
            static fn (array $row): bool => $row['email'] === strtolower(trim($email))
        ));
    }

    public function savePendingSender(
        string $email,
        ?int $suggestedParishId,
        ?string $source,
        string $timestamp
    ): void {
        $id = $this->nextId++;
        $this->rows[$id] = [
            'id' => $id,
            'parish_id' => 0,
            'email' => strtolower(trim($email)),
            'display_name' => '',
            'role_label' => '',
            'receives_reminders' => 0,
            'trust' => SenderTrust::PENDING,
            'verified_at' => null,
            'suggested_parish_id' => $suggestedParishId,
            'suggestion_source' => $source,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
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
