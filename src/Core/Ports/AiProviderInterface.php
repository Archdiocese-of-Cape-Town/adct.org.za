<?php

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\ParseResult;

interface AiProviderInterface
{
    public function name(): string;

    public function isAvailable(): bool;

    public function enrich(Message $message, ParseResult $result): array;
}
