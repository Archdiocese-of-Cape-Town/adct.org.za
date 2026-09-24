<?php

namespace ADCT\ParishIntake\Core\Parsing;

use ADCT\ParishIntake\Core\Parsing\Contracts\StageInterface;
use ADCT\ParishIntake\Core\Parsing\Input\Message;

final class Pipeline
{
    /** @var StageInterface[] */
    private array $stages;
    private ParseContext $context;

    public function __construct(array $stages, ?ParseContext $context = null)
    {
        $this->stages = $stages;
        $this->context = $context ?? new ParseContext();
    }

    public function parse(Message $message): ParseResult
    {
        $this->context->reset();
        $result = new ParseResult();
        $result->setParserVersion((string) $this->context->getOption('parser_version', '0.1.0'));

        foreach ($this->stages as $stage) {
            $result = $stage->process($message, $result, $this->context);
        }

        foreach ($this->context->notes() as $note) {
            $result->addNote($note);
        }

        foreach ($this->context->errors() as $error) {
            $result->addError($error);
        }

        return $result;
    }
}
