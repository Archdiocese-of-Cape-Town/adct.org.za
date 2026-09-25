<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Parsing;

use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\PipelineFactory;
use ADCT\ParishIntake\Core\Support\EmailTextCleaner;
use PHPUnit\Framework\TestCase;

final class EmailTextCleanerTest extends TestCase
{
    public function testSeparatesReplyQuoteAndSignature(): void
    {
        $text = <<<'EMAIL'
Parish: Example Parish
The gathering is now scheduled for 11 October 2026 at 17:30 at Example Parish Hall.

Kind regards,

Jamie Example
office@example.test
021 555 0101

On 24 September 2026, Example Parish Office wrote:
> The gathering will be held on 10 October 2026 at 16:00 at Example Parish Hall.
EMAIL;

        $cleaned = (new EmailTextCleaner())->clean($text, 'Re: Community gathering update');

        self::assertSame(
            "Parish: Example Parish\nThe gathering is now scheduled for 11 October 2026 at 17:30 at Example Parish Hall.",
            $cleaned->getBody()
        );
        self::assertSame(
            "On 24 September 2026, Example Parish Office wrote:\n> The gathering will be held on 10 October 2026 at 16:00 at Example Parish Hall.",
            $cleaned->getQuotedText()
        );
        self::assertSame(
            "Kind regards,\n\nJamie Example\noffice@example.test\n021 555 0101",
            $cleaned->getSignatureText()
        );
    }

    public function testSeparatesOutlookOriginalMessageAsQuotedText(): void
    {
        $text = <<<'EMAIL'
The gathering has moved to Sunday.

-----Original Message-----
From: Example Parish Office <events@example.test>
Sent: Thursday, 24 September 2026 09:00
To: events@example.test
Subject: Community gathering

The gathering will be held on 10 October 2026 at 16:00 at Example Parish Hall.
EMAIL;

        $cleaned = (new EmailTextCleaner())->clean($text, 'Re: Community gathering');

        self::assertSame('The gathering has moved to Sunday.', $cleaned->getBody());
        self::assertStringContainsString('From: Example Parish Office', $cleaned->getQuotedText());
        self::assertStringContainsString('10 October 2026', $cleaned->getQuotedText());
    }

    public function testSeparatesOutlookReplyHeaderBlockWithoutSubject(): void
    {
        $text = <<<'EMAIL'
The gathering has moved to Sunday.

From: Example Parish Office <events@example.test>
Sent: Thursday, 24 September 2026 09:00
To: events@example.test

The gathering was previously planned for 10 October 2026.
EMAIL;

        $cleaned = (new EmailTextCleaner())->clean($text, 'Re: Community gathering');

        self::assertSame('The gathering has moved to Sunday.', $cleaned->getBody());
        self::assertStringContainsString('Sent: Thursday, 24 September 2026 09:00', $cleaned->getQuotedText());
    }

    public function testDetectsForwardAndExtractsOriginalHeaders(): void
    {
        $text = <<<'EMAIL'
Please see this event.

---------- Forwarded message ---------
From: Example Parish Youth Team <youth@example.test>
Date: Thu, 01 Oct 2026 08:00:00 +0200
Subject: Youth gathering
To: events@example.test

Parish: Example Parish
Please join the youth gathering on Saturday 10 October 2026 at 10:00 at Example Parish Hall.
EMAIL;

        $cleaned = (new EmailTextCleaner())->clean($text, 'Fwd: Youth gathering');

        self::assertTrue($cleaned->isForwarded());
        self::assertSame('youth@example.test', $cleaned->getOriginalSenderEmail());
        self::assertSame('Example Parish Youth Team', $cleaned->getOriginalSenderName());
        self::assertSame('Thu, 01 Oct 2026 08:00:00 +0200', $cleaned->getOriginalDate());
        self::assertSame('Youth gathering', $cleaned->getOriginalSubject());
        self::assertStringContainsString('Please see this event.', $cleaned->getBody());
        self::assertStringContainsString('10 October 2026', $cleaned->getBody());
        self::assertStringNotContainsString('Forwarded message', $cleaned->getBody());
        self::assertSame('', $cleaned->getQuotedText());
    }

    public function testDetectsOutlookForwardHeaderBlock(): void
    {
        $text = <<<'EMAIL'
For your information:

From: Example Parish Youth Team <youth@example.test>
Sent: Thursday, 01 October 2026 08:00
To: events@example.test
Subject: Youth volunteer day

Parish: Example Parish
Join us for a youth volunteer day on Saturday 24 October 2026 at 09:00 at Example Parish Hall.
EMAIL;

        $cleaned = (new EmailTextCleaner())->clean($text, 'FW: Youth volunteer day');

        self::assertTrue($cleaned->isForwarded());
        self::assertSame('youth@example.test', $cleaned->getOriginalSenderEmail());
        self::assertSame('Example Parish Youth Team', $cleaned->getOriginalSenderName());
        self::assertSame('Thursday, 01 October 2026 08:00', $cleaned->getOriginalDate());
        self::assertSame('Youth volunteer day', $cleaned->getOriginalSubject());
        self::assertSame(
            "For your information:\n\nParish: Example Parish\nJoin us for a youth volunteer day on Saturday 24 October 2026 at 09:00 at Example Parish Hall.",
            $cleaned->getBody()
        );
    }

    public function testSeparatesMobileSignatureAndRemovesNewsletterFooter(): void
    {
        $text = <<<'EMAIL'
Parish: Example Parish
Join us for Community Day on Saturday 24 October 2026 at 11:00 at Example Parish Hall.

Sent from my iPhone

View this email in your browser.
Unsubscribe from this fictional mailing list.
Update your preferences.
Follow Example Parish on the example.test website.
EMAIL;

        $cleaned = (new EmailTextCleaner())->clean($text, 'Community Day');

        self::assertSame(
            "Parish: Example Parish\nJoin us for Community Day on Saturday 24 October 2026 at 11:00 at Example Parish Hall.",
            $cleaned->getBody()
        );
        self::assertSame('Sent from my iPhone', $cleaned->getSignatureText());
        self::assertStringNotContainsString('Unsubscribe', $cleaned->getBody());
    }

    public function testDoesNotTreatAnOrdinaryClosingAsASignature(): void
    {
        $cleaned = (new EmailTextCleaner())->clean(
            "Please reply if you have questions.\nThanks for your help.",
            'A quick question'
        );

        self::assertSame("Please reply if you have questions.\nThanks for your help.", $cleaned->getBody());
        self::assertSame('', $cleaned->getSignatureText());
    }

    public function testPipelineUsesCleanBodyAndSignatureContacts(): void
    {
        $message = new Message(
            'email',
            'cleaned-pipeline',
            'sender@example.test',
            'Example Parish Office',
            'Community gathering',
            "Parish: Example Parish\nThe gathering is on 11 October 2026 at 17:30 at Example Parish Hall.\n\nKind regards,\nJamie Example\noffice@example.test\n021 555 0101\n\nOn 24 September 2026, Example Parish Office wrote:\n> The older date was 10 October 2026."
        );

        $result = (new PipelineFactory())->create()->parse($message)->toArray();

        self::assertSame(
            'The gathering is on 11 October 2026 at 17:30 at Example Parish Hall.',
            $result['fields']['description']
        );
        self::assertSame('office@example.test | 021 555 0101', $result['fields']['contact']);
        self::assertStringNotContainsString('older date', $result['normalized_text']);
        self::assertStringNotContainsString('Kind regards', $result['normalized_text']);
    }
}
