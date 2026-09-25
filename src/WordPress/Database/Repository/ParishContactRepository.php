<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database\Repository;

use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use RuntimeException;

final class ParishContactRepository
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
}
