<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Mail {
    function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        WordPressMailDeliveryFixture::$hooks[$hook][] = $callback;
    }

    function remove_action(string $hook, callable $callback, int $priority = 10): void
    {
        WordPressMailDeliveryFixture::$hooks[$hook] = array_values(array_filter(
            WordPressMailDeliveryFixture::$hooks[$hook] ?? [],
            static fn (callable $registered): bool => $registered !== $callback
        ));
    }

    function wp_mail($to, $subject, $message, $headers = [], $attachments = []): bool
    {
        WordPressMailDeliveryFixture::$arguments = [
            'to' => $to,
            'subject' => $subject,
            'message' => $message,
            'headers' => $headers,
            'attachments' => $attachments,
        ];

        if (WordPressMailDeliveryFixture::$exception !== null) {
            throw WordPressMailDeliveryFixture::$exception;
        }

        $mailer = new WordPressMailDeliveryMailerFixture();

        foreach (WordPressMailDeliveryFixture::$hooks['phpmailer_init'] ?? [] as $callback) {
            $callback($mailer);
        }

        WordPressMailDeliveryFixture::$alternativeBody = $mailer->AltBody;

        return WordPressMailDeliveryFixture::$result;
    }

    final class WordPressMailDeliveryMailerFixture
    {
        public string $AltBody = '';
    }

    final class WordPressMailDeliveryFixture
    {
        public static array $hooks = [];
        public static array $arguments = [];
        public static ?string $alternativeBody = null;
        public static bool $result = true;
        public static ?\Throwable $exception = null;

        public static function reset(): void
        {
            self::$hooks = [];
            self::$arguments = [];
            self::$alternativeBody = null;
            self::$result = true;
            self::$exception = null;
        }
    }
}

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Mail {
    use ADCT\ParishIntake\Core\Mail\MailPriority;
    use ADCT\ParishIntake\Core\Mail\EmailThreadHeaders;
    use ADCT\ParishIntake\Core\Mail\OutboundEmail;
    use ADCT\ParishIntake\WordPress\Mail\WordPressMailDeliveryAdapter;
    use ADCT\ParishIntake\WordPress\Mail\WordPressMailDeliveryFixture;
    use PHPUnit\Framework\TestCase;
    use RuntimeException;

    final class WordPressMailDeliveryAdapterTest extends TestCase
    {
        protected function setUp(): void
        {
            WordPressMailDeliveryFixture::reset();
        }

        public function testDeliversHtmlWithTextAlternativeToOneRecipientAndNoAttachments(): void
        {
            $email = new OutboundEmail(
                'parish@example.test',
                'A fictional notice',
                '<p>HTML notice</p>',
                'Plain-text notice',
                MailPriority::APPROVER_OR_CHANGE
            );

            $result = (new WordPressMailDeliveryAdapter())->deliver($email);

            self::assertTrue($result->sent);
            self::assertSame([
                'to' => 'parish@example.test',
                'subject' => 'A fictional notice',
                'message' => '<p>HTML notice</p>',
                'headers' => ['Content-Type: text/html; charset=UTF-8'],
                'attachments' => [],
            ], WordPressMailDeliveryFixture::$arguments);
            self::assertSame('Plain-text notice', WordPressMailDeliveryFixture::$alternativeBody);
            self::assertSame([], WordPressMailDeliveryFixture::$hooks['phpmailer_init']);
        }

        public function testTextOnlyEmailUsesPlainTextContentType(): void
        {
            $email = new OutboundEmail(
                'parish@example.test',
                'A fictional notice',
                '',
                'Plain-text notice',
                MailPriority::APPROVER_OR_CHANGE
            );

            $result = (new WordPressMailDeliveryAdapter())->deliver($email);

            self::assertTrue($result->sent);
            self::assertSame('Plain-text notice', WordPressMailDeliveryFixture::$arguments['message']);
            self::assertSame(
                ['Content-Type: text/plain; charset=UTF-8'],
                WordPressMailDeliveryFixture::$arguments['headers']
            );
            self::assertSame('', WordPressMailDeliveryFixture::$alternativeBody);
        }

        public function testDeliveryAddsValidatedThreadHeaders(): void
        {
            $email = new OutboundEmail(
                'parish@example.test',
                'A fictional notice',
                '<p>HTML notice</p>',
                'Plain-text notice',
                MailPriority::APPROVER_OR_CHANGE,
                null,
                EmailThreadHeaders::fromOriginalMessageId('<inbound@example.test>')
            );

            $result = (new WordPressMailDeliveryAdapter())->deliver($email);

            self::assertTrue($result->sent);
            self::assertSame([
                'Content-Type: text/html; charset=UTF-8',
                'In-Reply-To: <inbound@example.test>',
                'References: <inbound@example.test>',
            ], WordPressMailDeliveryFixture::$arguments['headers']);
        }

        public function testFalseWpMailResultProducesSanitizedFailure(): void
        {
            WordPressMailDeliveryFixture::$result = false;

            $result = (new WordPressMailDeliveryAdapter())->deliver($this->email());

            self::assertFalse($result->sent);
            self::assertSame('wp_mail_returned_false', $result->failureCode);
        }

        public function testTransportExceptionIsReportedAsAnUnknownOutcomeWithoutDetails(): void
        {
            WordPressMailDeliveryFixture::$exception = new RuntimeException(
                'SMTP secret and private message content'
            );

            $result = (new WordPressMailDeliveryAdapter())->deliver($this->email());

            self::assertFalse($result->sent);
            self::assertTrue($result->outcomeUnknown);
            self::assertNull($result->failureCode);
        }

        private function email(): OutboundEmail
        {
            return new OutboundEmail(
                'parish@example.test',
                'A fictional notice',
                '<p>HTML notice</p>',
                'Plain-text notice',
                MailPriority::APPROVER_OR_CHANGE
            );
        }
    }
}
