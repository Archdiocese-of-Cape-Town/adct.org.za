<?php

namespace ADCT\ParishIntake\Parsing\Ai;

use ADCT\ParishIntake\Parsing\Input\Message;
use ADCT\ParishIntake\Parsing\ParseResult;

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
