<?php

namespace ADCT\ParishIntake\Parsing\Ai;

use ADCT\ParishIntake\Parsing\Input\Message;
use ADCT\ParishIntake\Parsing\ParseResult;

interface AiProviderInterface
{
    public function name(): string;

    public function isAvailable(): bool;

    public function enrich(Message $message, ParseResult $result): array;
}
