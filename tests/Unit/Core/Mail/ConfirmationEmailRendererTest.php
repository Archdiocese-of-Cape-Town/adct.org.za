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
    public function testPublishedChangePreviewNamesTheExistingEventAndChangeKind(): void
    {
        foreach ([
            'update' => 'This will update: Parish market',
            'cancellation' => 'This will cancel: Parish market',
            'postponement' => 'This will postpone: Parish market',
        ] as $kind => $label) {
            $candidate = new ConfirmationEmailCandidate(
                101,
                ['title' => 'Parish market', 'event_date' => '2026-10-17', 'event_time' => '10:00'],
                [],
                0.9,
                [],
                $kind,
                'Parish market'
            );
            $batch = new ConfirmationEmailBatch(
                901, 4, 'sender@example.test', 'Example Sender', 'Event notice',
                new DateTimeImmutable('2026-10-01 12:00:00', new DateTimeZone('Africa/Johannesburg')),
                null, SenderTrust::UNKNOWN, SenderTrust::UNKNOWN, false, null, [$candidate]
            );
            $links = new ConfirmationEmailActionLinks(
                'https://adct.example.test/action?token=all',
                [101 => [
                    'approve' => 'https://adct.example.test/action?token=approve',
                    'deny' => 'https://adct.example.test/action?token=deny',
                    'edit' => 'https://adct.example.test/action?token=edit',
                ]]
            );
            $content = (new ConfirmationEmailRenderer())->render($batch, $links);
            self::assertStringContainsString($label, $content->html);
            self::assertStringContainsString($label, $content->text);
        }
    }

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
        self::assertStringContainsString('Confirm all:', $content->text);
        self::assertStringContainsString('the Edit link is not active yet', $content->text);
    }

    /**
     * Issue #130: a field the parser invented must be marked for checking, while fields it actually
     * read from the notice must not be swept up by the weak overall score.
     */
    public function testFabricatedFieldIsMarkedWhileWellEvidencedFieldsAreNot(): void
    {
        $candidate = new ConfirmationEmailCandidate(
            101,
            [
                'title' => 'Community supper',
                'event_date' => '2026-10-12',
                'event_end_date' => null,
                'event_time' => '18:30',
                'event_end_time' => null,
                'venue' => 'Example Parish Hall',
                'venue_address' => null,
                'venue_suburb' => null,
                'parish_name' => 'Example Parish',
                'description' => 'Bring a chair.',
                'event_type' => 'Meeting',
                'contact' => null,
                'all_day' => false,
            ],
            [],
            0.42,
            [],
            'new',
            null,
            [
                'score' => 0.42,
                'coverage' => 0.8,
                'fields' => [
                    'title' => ['score' => 0.98, 'origin' => 'explicit', 'flags' => []],
                    'event_date' => ['score' => 0.98, 'origin' => 'explicit', 'flags' => []],
                    'event_time' => ['score' => 0.92, 'origin' => 'context', 'flags' => []],
                    'venue' => ['score' => 0.96, 'origin' => 'directory_text', 'flags' => []],
                    'parish_name' => [
                        'score' => 0.0,
                        'origin' => 'unsupported',
                        'flags' => ['unanchored_parish_match'],
                    ],
                    'description' => ['score' => 0.98, 'origin' => 'explicit', 'flags' => []],
                    'event_type' => ['score' => 0.85, 'origin' => 'inferred', 'flags' => []],
                ],
            ]
        );

        $content = (new ConfirmationEmailRenderer())->render(
            $this->batch([$candidate]),
            $this->links()
        );

        self::assertStringContainsString('Parish: Example Parish (please check)', $content->text);
        self::assertStringNotContainsString('Date: 12 October 2026 (please check)', $content->text);
        self::assertStringNotContainsString('Description: Bring a chair. (please check)', $content->text);
        self::assertStringNotContainsString('Time: 6:30 pm (please check)', $content->text);
        // The 0.42 overall score is below the review threshold, but must not blank out good fields.
        self::assertStringContainsString('please review all details carefully', $content->html);
    }

    public function testFieldWithoutARecordedScoreIsNotMarked(): void
    {
        $candidate = new ConfirmationEmailCandidate(
            101,
            [
                'title' => 'Community supper',
                'event_date' => '2026-10-12',
                'event_end_date' => null,
                'event_time' => '18:30',
                'event_end_time' => null,
                'venue' => 'Example Parish Hall',
                'venue_address' => null,
                'venue_suburb' => null,
                'parish_name' => 'Example Parish',
                'description' => null,
                'event_type' => null,
                'contact' => null,
                'all_day' => false,
            ],
            [],
            0.95,
            [],
            'new',
            null,
            [
                'score' => 0.95,
                'coverage' => 0.8,
                'fields' => [
                    'title' => ['score' => 0.98, 'origin' => 'explicit', 'flags' => []],
                ],
            ]
        );

        $content = (new ConfirmationEmailRenderer())->render(
            $this->batch([$candidate]),
            $this->links()
        );

        // A high overall score, and no recorded score for the parish: not marked.
        self::assertStringNotContainsString('Parish: Example Parish (please check)', $content->text);
        // Fields with no evidence and no value still read as "not identified", as before.
        self::assertStringContainsString('Description: Not identified', $content->text);
    }

    private function batch(array $candidates): ConfirmationEmailBatch
    {
        return new ConfirmationEmailBatch(
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
            null,
            $candidates
        );
    }

    private function links(): ConfirmationEmailActionLinks
    {
        return new ConfirmationEmailActionLinks(
            'https://adct.example.test/action?token=all',
            [
                101 => [
                    'approve' => 'https://adct.example.test/action?token=approve',
                    'deny' => 'https://adct.example.test/action?token=deny',
                    'edit' => 'https://adct.example.test/action?token=edit',
                ],
            ]
        );
    }
}
