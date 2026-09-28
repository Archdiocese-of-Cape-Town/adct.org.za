<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Mail;

use ADCT\ParishIntake\Core\Mail\ConfirmationEmailActionLinks;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailBatch;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailCandidate;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailRenderer;
use ADCT\ParishIntake\Core\Directory\SenderTrust;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class ConfirmationEmailRendererTest extends TestCase
{
    public function testHtmlAndPlainTextRenderEveryCandidateAndEscapeUntrustedValues(): void
    {
        $candidate = new ConfirmationEmailCandidate(
            101,
            [
                'title' => 'Parish Harvest <script>alert("x")</script>',
                'event_date' => '2026-10-12',
                'event_end_date' => null,
                'event_time' => '18:30',
                'event_end_time' => '19:45',
                'venue' => 'St <Example> Hall',
                'venue_address' => '1 Example & Main Street',
                'venue_suburb' => 'Cape Town',
                'parish_name' => 'St Example Parish',
                'description' => "A & B\nBring <b>a chair</b>",
                'event_type' => 'Fundraising',
                'contact' => 'Office: office@example.test; Phone: 000 000 0000',
                'all_day' => false,
            ],
            [],
            0.48,
            ['The stated weekday does not match the parsed event date; verify it.']
        );
        $batch = new ConfirmationEmailBatch(
            901,
            4,
            'sender@example.test',
            'Example Sender',
            'Event notice',
            new DateTimeImmutable('2026-10-01 12:00:00', new DateTimeZone('Africa/Johannesburg')),
            null,
            SenderTrust::UNKNOWN,
            SenderTrust::UNKNOWN,
            false,
            '<original-901@example.test>',
            [$candidate]
        );
        $links = new ConfirmationEmailActionLinks(
            'https://adct.example.test/action?token=approve-all-901',
            [
                101 => [
                    'approve' => 'https://adct.example.test/action?token=approve-101',
                    'deny' => 'https://adct.example.test/action?token=deny-101',
                    'edit' => 'https://adct.example.test/action?token=edit-101',
                ],
            ]
        );

        $content = (new ConfirmationEmailRenderer())->render($batch, $links);
        $fixtures = dirname(__DIR__, 4) . '/tests/fixtures/confirmation-email';
        $expectedHtml = file_get_contents($fixtures . '/preview.html');
        $expectedText = file_get_contents($fixtures . '/preview.txt');

        self::assertIsString($expectedHtml);
        self::assertIsString($expectedText);
        $expectedHtml = str_replace("\r\n", "\n", $expectedHtml);
        $expectedText = str_replace("\r\n", "\n", $expectedText);
        $expectedHtml = rtrim($expectedHtml, "\n");
        $expectedText = rtrim($expectedText, "\n");
        self::assertSame($expectedHtml, $content->html);
        self::assertSame($expectedText, $content->text);
        self::assertSame(
            'Please review your event preview — Archdiocese of Cape Town',
            $content->subject
        );
        self::assertStringContainsString('&lt;script&gt;', $content->html);
        self::assertStringContainsString('St &lt;Example&gt; Hall', $content->html);
        self::assertStringContainsString('Bring &lt;b&gt;a chair&lt;/b&gt;', $content->html);
        self::assertStringNotContainsString('<script>', $content->html);
        self::assertStringContainsString('aria-label="please check"', $content->html);
        self::assertStringContainsString('color:#704f00;">Please check</span>', $content->html);
        self::assertStringContainsString('(please check)', $content->text);
        self::assertStringContainsString('Approve all (not active)', $content->text);
        self::assertStringContainsString('clicking one will not approve, deny, edit, or publish', $content->text);
    }
}
