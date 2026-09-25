<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Ingestion;

use ADCT\ParishIntake\Core\Ingestion\MessageContentHasher;
use PHPUnit\Framework\TestCase;

final class MessageContentHasherTest extends TestCase
{
    public function testNormalisesLineEndingsAndWhitespace(): void
    {
        $hasher = new MessageContentHasher();

        self::assertSame(
            $hasher->hash("A notice.\r\n \tSecond line.\r\n\r\n\r\n"),
            $hasher->hash("A notice.\nSecond line.\n\n")
        );
    }

    public function testAttachmentOrderDoesNotChangeTheContentHash(): void
    {
        $hasher = new MessageContentHasher();
        $first = hash('sha256', 'poster one');
        $second = hash('sha256', 'poster two');

        self::assertSame(
            $hasher->hash('Example event', [$first, $second]),
            $hasher->hash('Example event', [$second, $first])
        );
    }

    public function testAttachmentContentChangesTheContentHash(): void
    {
        $hasher = new MessageContentHasher();

        self::assertNotSame(
            $hasher->hash('Example event', [hash('sha256', 'first poster')]),
            $hasher->hash('Example event', [hash('sha256', 'second poster')])
        );
    }
}
