<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Sources\Source;

interface SourceStoreInterface
{
    public function findSource(int $sourceId): ?Source;

    /**
     * @return list<Source>
     */
    public function findForParish(int $parishId): array;

    public function saveSource(Source $source, string $timestamp): Source;

    public function registerOfficialEmailSourceIfMissing(
        int $parishId,
        string $email,
        string $timestamp
    ): bool;
}
