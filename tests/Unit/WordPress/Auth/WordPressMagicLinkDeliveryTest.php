<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Auth {

    if (! function_exists(__NAMESPACE__ . '\\esc_html__')) {
        function esc_html__(string $text, string $domain = 'default'): string
        {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        }
    }

    if (! function_exists(__NAMESPACE__ . '\\esc_url')) {
        /**
         * Close enough to WordPress's own filter to catch a quote or a tag
         * breaking out of the href, which is the point of the escaping test.
         */
        function esc_url(mixed $value): string
        {
            return htmlspecialchars(is_string($value) ? $value : '', ENT_QUOTES, 'UTF-8');
        }
    }
}

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Auth {

    use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
    use ADCT\ParishIntake\Core\Auth\ActionTokenDeliveryException;
    use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
    use ADCT\ParishIntake\Core\Auth\IssuedActionToken;
    use ADCT\ParishIntake\Core\Mail\MailPriority;
    use ADCT\ParishIntake\Core\Mail\MailQueueEnqueueResult;
    use ADCT\ParishIntake\Core\Mail\MailQueueStatus;
    use ADCT\ParishIntake\Core\Mail\OutboundEmail;
    use ADCT\ParishIntake\Core\Ports\MailerInterface;
    use ADCT\ParishIntake\WordPress\Auth\WordPressMagicLinkDelivery;
    use DateTimeImmutable;
    use PHPUnit\Framework\TestCase;

    /**
     * The sign-in mail goes out through the queue, never through wp_mail()
     * (ADR 0011), because the hosting account caps the whole site at 500 mails
     * an hour and every one of them has to be visible to an administrator.
     */
    final class WordPressMagicLinkDeliveryTest extends TestCase
    {
        public function testTheLinkIsQueuedRatherThanSentDirectly(): void
        {
            $mailer = new LoginRecordingMailer(MailQueueStatus::QUEUED);

            (new WordPressMagicLinkDelivery($mailer, 'https://adct.test/?adct_pi_token=abc'))
                ->deliver($this->binding(), $this->token());

            self::assertCount(1, $mailer->queued);
            $email = $mailer->queued[0];
            self::assertSame('dean@example.test', $email->recipient);
            self::assertStringContainsString('adct_pi_token=abc', $email->htmlBody);
            self::assertStringContainsString('adct_pi_token=abc', $email->textBody);
        }

        /**
         * ADR 0011 also wants the sign-in to jump the queue: a dean waiting on a
         * 30-minute link is the most time-critical mail the plugin sends.
         */
        public function testTheMailIsQueuedAtTheHighestPriority(): void
        {
            $mailer = new LoginRecordingMailer(MailQueueStatus::QUEUED);

            (new WordPressMagicLinkDelivery($mailer, 'https://adct.test/x'))->deliver($this->binding(), $this->token());

            self::assertSame(MailPriority::LOGIN_OR_CONFIRMATION, $mailer->queued[0]->priority);
        }

        /**
         * Both bodies must carry the link: the text body is what a plain-text
         * client shows, and a dean whose client strips HTML would otherwise get
         * a mail with no way in.
         */
        public function testTheMessageSaysHowLongTheLinkLasts(): void
        {
            $mailer = new LoginRecordingMailer(MailQueueStatus::QUEUED);

            (new WordPressMagicLinkDelivery($mailer, 'https://adct.test/x'))->deliver($this->binding(), $this->token());

            $email = $mailer->queued[0];
            self::assertStringContainsString('30 minutes', $email->textBody);
            self::assertStringContainsString('30 minutes', $email->htmlBody);
            self::assertStringContainsString('once', $email->textBody);
        }

        /**
         * The URL is attacker-supplied in the sense that it is assembled from a
         * site option; a quote in it must not break out of the href attribute.
         */
        public function testTheLinkIsEscapedInTheHtmlBody(): void
    {
            $mailer = new LoginRecordingMailer(MailQueueStatus::QUEUED);
            $url = 'https://adct.test/?adct_pi_token=a"><script>alert(1)</script>';

            (new WordPressMagicLinkDelivery($mailer, $url))->deliver($this->binding(), $this->token());

            $html = $mailer->queued[0]->htmlBody;
            self::assertStringNotContainsString('<script>', $html);
            self::assertStringContainsString('&lt;script&gt;', $html);
        }

        /**
         * The dedupe key is derived from the token rather than the recipient, so
         * a second request mints a different token and therefore a different key
         * — while a retried queue insert for the same token collapses to one
         * message instead of mailing a dean twice.
         */
        public function testTheDedupeKeyIsDerivedFromTheTokenNotTheRecipient(): void
        {
            $mailer = new LoginRecordingMailer(MailQueueStatus::QUEUED);
            $delivery = new WordPressMagicLinkDelivery($mailer, 'https://adct.test/x');

            $delivery->deliver($this->binding(), $this->token('first'));
            $delivery->deliver($this->binding(), $this->token('second'));
            $delivery->deliver($this->binding(), $this->token('first'));

            $keys = array_map(static fn (OutboundEmail $e): ?string => $e->groupKey, $mailer->queued);
            self::assertSame($keys[0], $keys[2], 'A retried send of one token must collapse.');
            self::assertNotSame($keys[0], $keys[1], 'A fresh token must not collapse into the old mail.');
            self::assertSame('magic-link-login:' . hash('sha256', 'first'), $keys[0]);
        }

        /**
         * A queue that already holds the row reports back through the result's
         * `duplicate` flag while keeping a healthy status. The dean has the mail
         * either way, so a retried send must not look like a failure.
         */
        public function testADeduplicatedEnqueueIsTreatedAsSuccess(): void
        {
            $mailer = new LoginRecordingMailer(MailQueueStatus::QUEUED, true);

            (new WordPressMagicLinkDelivery($mailer, 'https://adct.test/x'))
                ->deliver($this->binding(), $this->token());

            self::assertCount(1, $mailer->queued);
        }

        /**
         * A suppressed row means the mail will not be sent — for example the
         * recipient bounced repeatedly. That must look like a failure, so the
         * caller can roll the token back rather than leave a dean with a link
         * that was never delivered.
         */
        public function testASuppressedEnqueueThrowsSoTheTokenIsNotLeftUndelivered(): void
        {
            $mailer = new LoginRecordingMailer(MailQueueStatus::SUPPRESSED);

            $this->expectException(ActionTokenDeliveryException::class);

            (new WordPressMagicLinkDelivery($mailer, 'https://adct.test/x'))
                ->deliver($this->binding(), $this->token());
        }

        /**
         * A queue that is out of quota must not look like a delivered link: the
         * caller records a delivery failure so the token is rolled back rather
         * than leaving a dean with a link that was never sent.
         */
        public function testAQueueFailureThrowsSoTheTokenIsNotLeftUndelivered(): void
    {
        $mailer = new LoginRecordingMailer(MailQueueStatus::FAILED);

        $this->expectException(ActionTokenDeliveryException::class);

        (new WordPressMagicLinkDelivery($mailer, 'https://adct.test/x'))
            ->deliver($this->binding(), $this->token());
    }

        public function testASendingQueueIsStillTreatedAsAccepted(): void
        {
            $mailer = new LoginRecordingMailer(MailQueueStatus::SENDING);

            (new WordPressMagicLinkDelivery($mailer, 'https://adct.test/x'))
                ->deliver($this->binding(), $this->token());

            self::assertCount(1, $mailer->queued);
        }

        private function binding(): ActionTokenBinding
        {
            return new ActionTokenBinding(ActionTokenPurpose::LOGIN, 'user', 42, 'Dean@Example.test');
        }

        private function token(string $token = 'raw-token'): IssuedActionToken
        {
            return new IssuedActionToken(
                $token,
                new DateTimeImmutable('2026-10-12T09:30:00+02:00')
            );
        }
    }

    /**
     * Captures what would have gone to the queue.
     */
    final class LoginRecordingMailer implements MailerInterface
    {
        /** @var list<OutboundEmail> */
        public array $queued = [];

        public function __construct(
            private MailQueueStatus $status,
            private bool $duplicate = false
        ) {
        }

        public function enqueue(OutboundEmail $email): MailQueueEnqueueResult
        {
            $this->queued[] = $email;

            return new MailQueueEnqueueResult(1, $this->status, $this->duplicate);
        }
    }
}