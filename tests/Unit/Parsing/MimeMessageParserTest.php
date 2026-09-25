<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Parsing;

use ADCT\ParishIntake\Core\Ingestion\MimeMessageParser;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class MimeMessageParserTest extends TestCase
{
    public function testParsesAlternativeBodyHeadersAndAttachmentReference(): void
    {
        $rawMessage = <<<'EMAIL'
From: Example Parish Office <events@example.test>
Subject: =?UTF-8?Q?Community_gathering?=
Date: Wed, 07 Oct 2026 09:30:00 +0200
Message-ID: <mime-message@example.test>
In-Reply-To: <parent@example.test>
References: <root@example.test> <parent@example.test>
Auto-Submitted: no
List-Id: Fictional parish notices <notices.example.test>
Authentication-Results: mx.example.test; spf=pass; dkim=pass header.d=example.test
MIME-Version: 1.0
Content-Type: multipart/mixed; boundary="mixed"

--mixed
Content-Type: multipart/alternative; boundary="alternative"

--alternative
Content-Type: text/plain; charset=UTF-8
Content-Transfer-Encoding: quoted-printable

Parish=3A Example Parish
Join us for the community gathering on 12 October 2026 at 18:30 at Example Parish Hall.
--alternative
Content-Type: text/html; charset=UTF-8
Content-Transfer-Encoding: quoted-printable

<html><body><p>HTML alternative must not replace useful plain text.</p></body></html>
--alternative--
--mixed
Content-Type: application/pdf; name="notice.pdf"
Content-Disposition: attachment; filename="notice.pdf"
Content-ID: <notice@example.test>
Content-Transfer-Encoding: base64

JVBERi0xLjQ=
--mixed--
EMAIL;

        $message = (new MimeMessageParser())->parse($rawMessage, 'mime-message-1');

        self::assertSame('email', $message->getSourceType());
        self::assertSame('mime-message-1', $message->getSourceIdentifier());
        self::assertSame('events@example.test', $message->getSenderEmail());
        self::assertSame('Example Parish Office', $message->getSenderName());
        self::assertSame('Community gathering', $message->getSubject());
        self::assertSame(
            "Parish: Example Parish\nJoin us for the community gathering on 12 October 2026 at 18:30 at Example Parish Hall.",
            $message->getBody()
        );
        self::assertFalse($message->isBodyHtmlDerived());
        self::assertInstanceOf(DateTimeImmutable::class, $message->getReceivedAt());
        self::assertSame('2026-10-07T09:30:00+02:00', $message->getReceivedAt()->format('Y-m-d\TH:i:sP'));
        self::assertSame('<mime-message@example.test>', $message->getMessageId());
        self::assertSame('<parent@example.test>', $message->getInReplyTo());
        self::assertSame('<root@example.test> <parent@example.test>', $message->getReferences());
        self::assertSame('no', $message->getAutoSubmitted());
        self::assertSame('Fictional parish notices <notices.example.test>', $message->getListId());
        self::assertSame(
            'mx.example.test; spf=pass; dkim=pass header.d=example.test',
            $message->getAuthenticationResults()
        );

        $attachments = $message->getAttachments();

        self::assertCount(1, $attachments);
        self::assertSame('notice.pdf', $attachments[0]->getName());
        self::assertSame('application/pdf', $attachments[0]->getMimeType());
        self::assertSame('mime-part:0', $attachments[0]->getContentReference());
        self::assertSame('notice@example.test', $attachments[0]->getContentId());
    }

    public function testUsesHtmlWhenPlainAlternativeIsEmptyOrOnlyAPlaceholder(): void
    {
        $rawMessage = <<<'EMAIL'
From: events@example.test
Subject: Community day
Date: Wed, 07 Oct 2026 09:30:00 +0200
MIME-Version: 1.0
Content-Type: multipart/alternative; boundary="alternative"

--alternative
Content-Type: text/plain; charset=UTF-8

This is a multi-part message in MIME format.
--alternative
Content-Type: text/html; charset=UTF-8

<html><body>
<p>Parish: Example Parish</p>
<p>Join us for Community Day on Saturday 17 October 2026 at 11:00 at Example Parish Hall.</p>
<ul><li>Morning prayer</li><li>Shared lunch</li></ul>
<table><tr><th>Date</th><th>Activity</th></tr><tr><td>17 October</td><td>Community Day</td></tr></table>
</body></html>
--alternative--
EMAIL;

        $message = (new MimeMessageParser())->parse($rawMessage, 'html-fallback');

        self::assertTrue($message->isBodyHtmlDerived());
        self::assertStringContainsString('Community Day on Saturday 17 October 2026', $message->getBody());
        self::assertStringContainsString('- Morning prayer', $message->getBody());
        self::assertStringContainsString('Date | Activity', $message->getBody());
    }

    public function testDecodesWindows1252QuotedPrintableText(): void
    {
        $rawMessage = <<<'EMAIL'
From: events@example.test
Subject: =?windows-1252?Q?Caf=E9_Day?=
Date: Wed, 07 Oct 2026 09:30:00 +0200
MIME-Version: 1.0
Content-Type: text/plain; charset=windows-1252
Content-Transfer-Encoding: quoted-printable

Parish=3A Example Parish
Join us for Caf=E9 Day on Saturday 24 October 2026 at 10:00 at Example Parish Hall; tickets cost =802.
EMAIL;

        $message = (new MimeMessageParser())->parse($rawMessage, 'windows-1252');

        self::assertSame('Café Day', $message->getSubject());
        self::assertStringContainsString('Café Day', $message->getBody());
        self::assertStringContainsString('€2.', $message->getBody());
    }

    public function testExposesOriginalForwardHeadersWithoutReplacingEnvelopeSender(): void
    {
        $rawMessage = <<<'EMAIL'
From: Forwarding Parish Office <events@example.test>
Subject: Fwd: Community supper
Date: Wed, 07 Oct 2026 12:30:00 +0200
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8

For your information.

---------- Forwarded message ---------
From: Example Parish Youth Team <youth@example.test>
Date: Tue, 06 Oct 2026 08:00:00 +0200
Subject: Community supper
To: events@example.test

Parish: Example Parish
Join us for the community supper on 12 October 2026 at 18:30 at Example Parish Hall.
EMAIL;

        $message = (new MimeMessageParser())->parse($rawMessage, 'forwarded-message');

        self::assertTrue($message->isForwarded());
        self::assertSame('events@example.test', $message->getSenderEmail());
        self::assertSame('Forwarding Parish Office', $message->getSenderName());
        self::assertSame('youth@example.test', $message->getOriginalSenderEmail());
        self::assertSame('Example Parish Youth Team', $message->getOriginalSenderName());
        self::assertSame('Tue, 06 Oct 2026 08:00:00 +0200', $message->getOriginalDate());
        self::assertSame('Community supper', $message->getOriginalSubject());
        self::assertStringContainsString('For your information.', $message->getBody());
        self::assertStringContainsString('12 October 2026', $message->getBody());
        self::assertStringNotContainsString('Forwarded message', $message->getBody());
    }
}
