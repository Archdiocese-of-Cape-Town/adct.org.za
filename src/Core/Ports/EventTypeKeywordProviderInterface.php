<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

interface EventTypeKeywordProviderInterface
{
    /** @return array<string, list<string>> Term slug to keyword list. */
    public function keywordLists(): array;
}
