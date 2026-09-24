<?php

namespace ADCT\ParishIntake\Core\Parsing\Ai;

use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\ParseResult;
use ADCT\ParishIntake\Core\Ports\AiProviderInterface;

final class NullAiProvider implements AiProviderInterface
{
    public function name(): string
    {
        return 'none';
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function enrich(Message $message, ParseResult $result): array
    {
        return [];
    }
}
