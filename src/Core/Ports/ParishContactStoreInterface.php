<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

interface ParishContactStoreInterface
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByEmail(string $email): array;

    /**
     * @return array<string, mixed>|null
     */
    public function findLink(int $contactId, int $parishId): ?array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findForParish(int $parishId): array;

    public function saveLink(
        int $parishId,
        string $email,
        string $displayName,
        string $roleLabel,
        bool $receivesReminders,
        string $trust,
        ?string $verifiedAt,
        string $timestamp
    ): void;

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
    ): int;

    public function deleteLink(int $contactId, int $parishId): int;

    public function setTrustForEmail(
        string $email,
        string $trust,
        ?string $verifiedAt,
        string $timestamp
    ): int;
}
