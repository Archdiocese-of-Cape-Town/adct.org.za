<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Ingestion\MailboxSettings;

interface MailboxSettingsStoreInterface
{
    /**
     * @return list<MailboxSettings>
     */
    public function findActiveMailboxes(): array;
}
