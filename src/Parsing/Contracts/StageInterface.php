<?php

namespace ADCT\ParishIntake\Parsing\Contracts;

use ADCT\ParishIntake\Parsing\Input\Message;
use ADCT\ParishIntake\Parsing\ParseContext;
use ADCT\ParishIntake\Parsing\ParseResult;

interface StageInterface
{
    public function process(Message $message, ParseResult $result, ParseContext $context): ParseResult;
}
