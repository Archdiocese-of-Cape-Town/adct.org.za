<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database\Repository;

use ADCT\ParishIntake\Core\Directory\SenderTrust;
use ADCT\ParishIntake\Core\Ports\ParishContactStoreInterface;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use RuntimeException;

final class ParishContactRepository implements ParishContactStoreInterface
{
    public function __construct(private DatabaseConnectionInterface $database)
    {
    }

    public function insertVerifiedIfMissing(int $parishId, string $email, string $verifiedAt): bool
    {
        if ($parishId < 1) {
            throw new RuntimeException('A parish ID is required when adding an office contact.');
        }

        $table = $this->database->prefix() . 'adct_pi_parish_contacts';
        $query = $this->database->prepare(
            "INSERT INTO {$table} "
            . '(parish_id, email, trust, verified_at, created_at, updated_at) '
            . "VALUES (%d, %s, 'verified', %s, %s, %s) "
            . 'ON DUPLICATE KEY UPDATE id = id',
            $parishId,
            strtolower(trim($email)),
            $verifiedAt,
            $verifiedAt,
            $verifiedAt
        );

        $this->database->clearLastError();
        $result = $this->database->query($query);

        if ($result === false) {
            throw new RuntimeException(
                'The parish office contact could not be saved: ' . $this->database->lastError()
            );
        }

        return $result > 0;
    }

    public function findByEmail(string $email): array
    {
        $table = $this->tableName();
        $query = $this->database->prepare(
            "SELECT id, parish_id, email, display_name, role_label, trust, verified_at, "
            . "receives_reminders, created_at, updated_at FROM {$table} "
            . 'WHERE email = %s ORDER BY parish_id ASC, id ASC',
            $email
        );

        return $this->fetchRows($query);
    }

    public function findLink(int $contactId, int $parishId): ?array
    {
        if ($contactId < 1 || $parishId < 1) {
            return null;
        }

        $table = $this->tableName();
        $query = $this->database->prepare(
            "SELECT id, parish_id, email, display_name, role_label, trust, verified_at, "
            . "receives_reminders, created_at, updated_at FROM {$table} "
            . 'WHERE id = %d AND parish_id = %d LIMIT 1',
            $contactId,
            $parishId
        );

        return $this->fetchRow($query);
    }

    public function findForParish(int $parishId): array
    {
        if ($parishId < 1) {
            return [];
        }

        $table = $this->tableName();
        $query = $this->database->prepare(
            "SELECT id, parish_id, email, display_name, role_label, trust, verified_at, "
            . "receives_reminders, created_at, updated_at FROM {$table} "
            . 'WHERE parish_id = %d ORDER BY email ASC, id ASC',
            $parishId
        );

        return $this->fetchRows($query);
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
        $this->assertValidLink($parishId, $trust);
        $table = $this->tableName();
        $verifiedAtPlaceholder = $verifiedAt === null ? 'NULL' : '%s';
        $query = "INSERT INTO {$table} "
            . '(parish_id, email, display_name, role_label, trust, verified_at, receives_reminders, created_at, updated_at) '
            . "VALUES (%d, %s, %s, %s, %s, {$verifiedAtPlaceholder}, %d, %s, %s) "
            . 'ON DUPLICATE KEY UPDATE '
            . 'display_name = VALUES(display_name), role_label = VALUES(role_label), '
            . 'trust = VALUES(trust), verified_at = VALUES(verified_at), '
            . 'receives_reminders = VALUES(receives_reminders), updated_at = VALUES(updated_at)';
        $arguments = [
            $parishId,
            $email,
            $displayName,
            $roleLabel,
            $trust,
        ];

        if ($verifiedAt !== null) {
            $arguments[] = $verifiedAt;
        }

        $arguments[] = $receivesReminders ? 1 : 0;
        $arguments[] = $timestamp;
        $arguments[] = $timestamp;
        $this->execute($this->database->prepare($query, ...$arguments), 'save parish contact');
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
        if ($contactId < 1) {
            throw new RuntimeException('A parish contact ID is required.');
        }

        $this->assertValidLink($parishId, $trust);
        $table = $this->tableName();
        $verifiedAtPlaceholder = $verifiedAt === null ? 'NULL' : '%s';
        $query = "UPDATE {$table} SET email = %s, display_name = %s, role_label = %s, "
            . "trust = %s, verified_at = {$verifiedAtPlaceholder}, receives_reminders = %d, updated_at = %s "
            . 'WHERE id = %d AND parish_id = %d';
        $arguments = [$email, $displayName, $roleLabel, $trust];

        if ($verifiedAt !== null) {
            $arguments[] = $verifiedAt;
        }

        array_push($arguments, $receivesReminders ? 1 : 0, $timestamp, $contactId, $parishId);

        return $this->execute($this->database->prepare($query, ...$arguments), 'update parish contact');
    }

    public function deleteLink(int $contactId, int $parishId): int
    {
        if ($contactId < 1 || $parishId < 1) {
            return 0;
        }

        $table = $this->tableName();
        $query = $this->database->prepare(
            "DELETE FROM {$table} WHERE id = %d AND parish_id = %d",
            $contactId,
            $parishId
        );

        return $this->execute($query, 'remove parish contact');
    }

    public function setTrustForEmail(
        string $email,
        string $trust,
        ?string $verifiedAt,
        string $timestamp
    ): int {
        if (! SenderTrust::isValid($trust)) {
            throw new RuntimeException('The sender trust state is not valid.');
        }

        $table = $this->tableName();
        $verifiedAtPlaceholder = $verifiedAt === null ? 'NULL' : '%s';
        $query = "UPDATE {$table} SET trust = %s, verified_at = {$verifiedAtPlaceholder}, updated_at = %s "
            . 'WHERE email = %s';
        $arguments = [$trust];

        if ($verifiedAt !== null) {
            $arguments[] = $verifiedAt;
        }

        array_push($arguments, $timestamp, $email);

        return $this->execute($this->database->prepare($query, ...$arguments), 'update sender trust');
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function findSenderAddresses(array $filters, int $limit, int $offset): array
    {
        $table = $this->tableName();
        [$where, $arguments] = $this->buildSenderWhere($filters);
        $query = "SELECT c.email, MIN(c.trust) AS trust, COUNT(DISTINCT c.trust) AS trust_count "
            . "FROM {$table} c{$where} GROUP BY c.email ORDER BY c.email ASC LIMIT %d OFFSET %d";
        $arguments[] = max(1, min(100, $limit));
        $arguments[] = max(0, $offset);

        return $this->fetchRows($this->database->prepare($query, ...$arguments));
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function countSenders(array $filters): int
    {
        $table = $this->tableName();
        [$where, $arguments] = $this->buildSenderWhere($filters);
        $query = "SELECT COUNT(DISTINCT c.email) AS total FROM {$table} c{$where}";
        $prepared = $arguments === []
            ? $query
            : $this->database->prepare($query, ...$arguments);
        $row = $this->fetchRow($prepared);

        return (int) ($row['total'] ?? 0);
    }

    /**
     * @param list<string> $emails
     * @return array<int, array<string, mixed>>
     */
    public function findSenderLinksByEmails(array $emails): array
    {
        $emails = array_values(array_unique($emails));

        if ($emails === []) {
            return [];
        }

        $table = $this->tableName();
        $parishes = $this->database->prefix() . 'adct_pi_parishes';
        $placeholders = implode(', ', array_fill(0, count($emails), '%s'));
        $query = "SELECT c.id, c.parish_id, c.email, c.display_name, c.role_label, c.trust, "
            . "c.verified_at, c.receives_reminders, p.name AS parish_name "
            . "FROM {$table} c LEFT JOIN {$parishes} p ON p.id = c.parish_id "
            . "WHERE c.email IN ({$placeholders}) ORDER BY c.email ASC, p.name ASC, c.id ASC";

        return $this->fetchRows($this->database->prepare($query, ...$emails));
    }

    private function tableName(): string
    {
        return $this->database->prefix() . 'adct_pi_parish_contacts';
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<int, scalar>}
     */
    private function buildSenderWhere(array $filters): array
    {
        $conditions = [];
        $arguments = [];
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $pattern = '%' . $this->database->escapeLike($search) . '%';
            $conditions[] = '(c.email LIKE %s OR c.display_name LIKE %s OR c.role_label LIKE %s)';
            array_push($arguments, $pattern, $pattern, $pattern);
        }

        $trust = trim((string) ($filters['trust'] ?? ''));

        if ($trust !== '') {
            if (! SenderTrust::isValid($trust)) {
                throw new RuntimeException('The sender trust filter is not valid.');
            }

            $conditions[] = 'c.trust = %s';
            $arguments[] = $trust;
        }

        return [
            $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions),
            $arguments,
        ];
    }

    private function assertValidLink(int $parishId, string $trust): void
    {
        if ($parishId < 1) {
            throw new RuntimeException('A parish ID is required when saving a contact.');
        }

        if (! SenderTrust::isValid($trust)) {
            throw new RuntimeException('The sender trust state is not valid.');
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchRows(string $query): array
    {
        $this->database->clearLastError();
        $rows = $this->database->getResults($query);

        if ($this->database->lastError() !== '') {
            throw new RuntimeException('The parish contact database read failed: ' . $this->database->lastError());
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchRow(string $query): ?array
    {
        $this->database->clearLastError();
        $row = $this->database->getRow($query);

        if ($this->database->lastError() !== '') {
            throw new RuntimeException('The parish contact database read failed: ' . $this->database->lastError());
        }

        return $row;
    }

    private function execute(string $query, string $operation): int
    {
        $this->database->clearLastError();
        $result = $this->database->query($query);

        if ($result === false) {
            throw new RuntimeException(
                'The database could not ' . $operation . ': ' . $this->database->lastError()
            );
        }

        return $result;
    }
}
