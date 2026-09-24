<?php

namespace ADCT\ParishIntake\Core\Parsing\Contracts;

use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\ParseContext;
use ADCT\ParishIntake\Core\Parsing\ParseResult;

interface StageInterface
{
    public function process(Message $message, ParseResult $result, ParseContext $context): ParseResult;
}
