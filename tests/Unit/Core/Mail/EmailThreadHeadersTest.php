<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Mail;

use ADCT\ParishIntake\Core\Mail\EmailThreadHeaders;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EmailThreadHeadersTest extends TestCase
{
    public function testBuildsSafeInReplyToAndReferencesHeadersFromOneMessageId(): void
    {
        $headers = EmailThreadHeaders::fromOriginalMessageId(' <bulletin-17@example.test> ');

        self::assertInstanceOf(EmailThreadHeaders::class, $headers);
        self::assertSame('<bulletin-17@example.test>', $headers->inReplyTo);
        self::assertSame('<bulletin-17@example.test>', $headers->references);
        self::assertSame([
            'In-Reply-To: <bulletin-17@example.test>',
            'References: <bulletin-17@example.test>',
        ], $headers->toHeaderLines());
    }

    public function testRejectsMissingMalformedAndHeaderInjectionMessageIds(): void
    {
        foreach ([
            null,
            '',
            'bulletin-17@example.test',
            '<bulletin 17@example.test>',
            "<bulletin-17@example.test>\r\nBcc: victim@example.test",
            '<first@example.test><second@example.test>',
            '<' . str_repeat('a', 260) . '@example.test>',
        ] as $messageId) {
            self::assertNull(EmailThreadHeaders::fromOriginalMessageId($messageId));
        }
    }

    public function testPersistedJsonRoundTripsOnlyValidatedThreadHeaders(): void
    {
        $headers = EmailThreadHeaders::fromOriginalMessageId('<bulletin-17@example.test>');

        self::assertInstanceOf(EmailThreadHeaders::class, $headers);
        self::assertEquals($headers, EmailThreadHeaders::fromJson($headers->toJson()));
    }

    public function testPersistedJsonCannotIntroduceUnvalidatedHeaderValues(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EmailThreadHeaders::fromJson(
            '{"in_reply_to":"<bulletin@example.test>\\r\\nBcc:victim@example.test",'
            . '"references":"<bulletin@example.test>"}'
        );
    }
}
