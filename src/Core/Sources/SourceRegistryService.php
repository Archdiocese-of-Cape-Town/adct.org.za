<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Sources;

use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\SourceStoreInterface;
use DateTimeZone;

final class SourceRegistryService
{
    public function __construct(
        private SourceStoreInterface $sources,
        private ClockInterface $clock
    ) {
    }

    public function save(Source $source): Source
    {
        return $this->sources->saveSource($source, $this->timestamp());
    }

    public function registerOfficialEmailIfMissing(int $parishId, string $email): bool
    {
        $normalizedEmail = SourceType::normalizeIdentifier(SourceType::EMAIL, $email);

        return $this->sources->registerOfficialEmailSourceIfMissing(
            $parishId,
            $normalizedEmail,
            $this->timestamp()
        );
    }

    private function timestamp(): string
    {
        return $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }
}
