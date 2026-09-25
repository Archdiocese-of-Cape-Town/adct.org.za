<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Parsing;

use ADCT\ParishIntake\Core\Parsing\Contracts\StageInterface;
use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\ParseContext;
use ADCT\ParishIntake\Core\Parsing\ParseResult;
use ADCT\ParishIntake\Core\Parsing\Pipeline;
use PHPUnit\Framework\TestCase;

final class PipelineContextTest extends TestCase
{
    public function testContextNotesAndErrorsDoNotLeakBetweenMessages(): void
    {
        $stage = new class implements StageInterface {
            public function process(Message $message, ParseResult $result, ParseContext $context): ParseResult
            {
                $identifier = $message->getSourceIdentifier();
                $context->addNote('note for ' . $identifier);
                $context->addError('error for ' . $identifier);

                return $result;
            }
        };

        $pipeline = new Pipeline([$stage], new ParseContext(['parser_version' => 'shared-option']));
        $first = $pipeline->parse(self::message('first'))->toArray();
        $second = $pipeline->parse(self::message('second'))->toArray();

        self::assertSame(['note for first'], $first['notes']);
        self::assertSame(['error for first'], $first['errors']);
        self::assertSame('shared-option', $first['parser_version']);
        self::assertSame(['note for second'], $second['notes']);
        self::assertSame(['error for second'], $second['errors']);
        self::assertSame('shared-option', $second['parser_version']);
    }

    public function testParseAllKeepsBlockNotesAndErrorsIsolated(): void
    {
        $stage = new class implements StageInterface {
            public function process(Message $message, ParseResult $result, ParseContext $context): ParseResult
            {
                $block = strpos($message->getBody(), 'First gathering') !== false ? 'first' : 'second';
                $context->addNote('note for ' . $block);
                $context->addError('error for ' . $block);

                return $result;
            }
        };
        $pipeline = new Pipeline([$stage], new ParseContext());
        $message = new Message(
            'email',
            'bulletin',
            'events@example.test',
            'Example Parish Office',
            'October bulletin',
            "EVENTS\n- First gathering on Saturday 10 October 2026 at 10:00.\n- Second gathering on Sunday 11 October 2026 at 11:00."
        );

        $outcome = $pipeline->parseAll($message);
        $candidates = $outcome->getCandidates();

        self::assertCount(2, $candidates);
        self::assertSame(['note for first'], $candidates[0]->getNotes());
        self::assertSame(['error for first'], $candidates[0]->getErrors());
        self::assertSame(['note for second'], $candidates[1]->getNotes());
        self::assertSame(['error for second'], $candidates[1]->getErrors());
        self::assertSame(['error for first', 'error for second'], $outcome->getErrors());
        self::assertContains('Split message into 2 event blocks.', $outcome->getNotes());
    }

    private static function message(string $identifier): Message
    {
        return new Message(
            'email',
            $identifier,
            'events@example.test',
            'Example Parish Office',
            'Example event',
            'An event at Example Parish Hall.'
        );
    }
}
