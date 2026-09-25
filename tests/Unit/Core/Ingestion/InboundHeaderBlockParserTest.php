<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Ingestion;

use ADCT\ParishIntake\Core\Ingestion\InboundHeaderBlockParser;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class InboundHeaderBlockParserTest extends TestCase
{
    public function testParsesFoldedHeadersAndExtractsOneSafeReplyToAndMessageId(): void
    {
        $parsed = (new InboundHeaderBlockParser())->parse(
            "From: Parish office <events@example.test>\r\n"
            . "Reply-To: Parish secretary\r\n"
            . "\t<secretary@example.test>\r\n"
            . "Message-ID: <notice-47@example.test>\r\n"
            . "List-Id: Parish events\r\n",
            'events@example.test'
        );

        self::assertSame('secretary@example.test', $parsed->replyToEmail);
        self::assertSame('<notice-47@example.test>', $parsed->messageId);
        self::assertTrue($parsed->automatedAssessment->blocksConfirmation());
        self::assertContains('list_id', $parsed->automatedAssessment->signals);
    }

    public function testRejectsAmbiguousReplyToAndMessageIds(): void
    {
        $parsed = (new InboundHeaderBlockParser())->parse(
            "Reply-To: first@example.test\r\n"
            . "Reply-To: second@example.test\r\n"
            . "Message-ID: <first@example.test>\r\n"
            . "Message-ID: <second@example.test>\r\n",
            'events@example.test'
        );

        self::assertNull($parsed->replyToEmail);
        self::assertNull($parsed->messageId);
    }

    public function testClassifiesAutomatedMessagesFromHeaderNamesWithoutCaseSensitivity(): void
    {
        $parsed = (new InboundHeaderBlockParser())->parse(
            "aUtO-sUbMiTtEd: auto-generated\r\n"
            . "lIsT-uNsUbScRiBe: <mailto:unsubscribe@example.test>\r\n",
            'events@example.test'
        );

        self::assertTrue($parsed->automatedAssessment->blocksConfirmation());
        self::assertContains('auto_submitted', $parsed->automatedAssessment->signals);
        self::assertContains('list_header', $parsed->automatedAssessment->signals);
    }

    public function testRejectsOversizedAndControlCharacterHeaderBlocks(): void
    {
        $parser = new InboundHeaderBlockParser();

        try {
            $parser->parse(str_repeat('X', InboundHeaderBlockParser::MAX_HEADER_BYTES + 1), null);
            self::fail('Expected oversized headers to be rejected.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);
        $parser->parse("Reply-To: events@example.test\x00\r\n", null);
    }
}
