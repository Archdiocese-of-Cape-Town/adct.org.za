<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Attachments {

    /**
     * Thrown instead of ending the request, so a test can assert on the
     * rejection that would otherwise have called `wp_die()`.
     */
    final class ImageRequestRefused extends \RuntimeException
    {
        public function __construct(public readonly int $status)
        {
            parent::__construct('The image request was refused with ' . $status . '.');
        }
    }

    function current_user_can(string $capability): bool
    {
        return $GLOBALS['image_endpoint_caps'][trim($capability)] ?? false;
    }

    function wp_die(string $message = '', $title = '', array $arguments = []): never
    {
        throw new ImageRequestRefused((int) ($arguments['response'] ?? 500));
    }

    /**
     * The endpoint calls this with the action only, matching how
     * `check_admin_referer()` may be invoked with a single argument.
     */
    function check_admin_referer(string $action = '-1', string $name = '_wpnonce'): void
    {
        if (($GLOBALS['image_endpoint_nonce_action'] ?? null) !== $action) {
            $GLOBALS['image_endpoint_nonce_failures'][] = $action;

            throw new ImageRequestRefused(403);
        }
    }

    function nocache_headers(): void
    {
        $GLOBALS['image_endpoint_nocache'] = true;
    }

    function admin_url(string $path = ''): string
    {
        return 'https://adct.test/wp-admin/' . ltrim($path, '/');
    }

    function add_query_arg(array $args, string $url): string
    {
        return $url . '?' . http_build_query($args);
    }

    function wp_nonce_url(string $url, string $action): string
    {
        return $url . '&_wpnonce=nonce-for-' . $action;
    }
}

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Attachments {

    use ADCT\ParishIntake\Core\Attachments\PreviewableImage;
    use ADCT\ParishIntake\Core\Auth\Capabilities;
    use ADCT\ParishIntake\Core\Ports\PreviewableImageRepositoryInterface;
    use ADCT\ParishIntake\WordPress\Attachments\ActionTokenImageEndpoint;
    use ADCT\ParishIntake\WordPress\Attachments\AttachmentImageEndpoint;
    use ADCT\ParishIntake\WordPress\Attachments\ImageRequestRefused;
    use ADCT\ParishIntake\WordPress\Ingestion\ProtectedInboundMailStorage;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    /**
     * This is the only way a stored inbound image reaches a browser, so the
     * tests are mostly about who is refused.
     */
    final class AttachmentImageEndpointTest extends TestCase
    {
        private const STORAGE_A = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90.jpg';

        private string $directory;

        protected function setUp(): void
        {
            $GLOBALS['image_endpoint_caps'] = [];
            $GLOBALS['image_endpoint_nonce_action'] = null;
            $GLOBALS['image_endpoint_nonce_failures'] = [];
            $GLOBALS['image_endpoint_nocache'] = false;
            unset($_GET[AttachmentImageEndpoint::ID_PARAM]);

            $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
                . 'adct-admin-image-' . bin2hex(random_bytes(6));
        }

        protected function tearDown(): void
        {
            foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                unlink($file);
            }

            if (is_dir($this->directory)) {
                rmdir($this->directory);
            }
        }

        public function testAUserWithoutTheReviewCapabilityIsRefused(): void
        {
            $GLOBALS['image_endpoint_caps'][Capabilities::REVIEW] = false;

            $this->assertRefused(403, fn () => $this->endpoint()->handleRequest());
        }

        public function testTheCapabilityIsCheckedBeforeAnythingElse(): void
        {
            $GLOBALS['image_endpoint_caps'][Capabilities::REVIEW] = false;
            $_GET[AttachmentImageEndpoint::ID_PARAM] = '11';

            try {
                $this->endpoint()->handleRequest();
            } catch (ImageRequestRefused) {
                // A refused request must not have got as far as the nonce.
            }

            self::assertSame([], $GLOBALS['image_endpoint_nonce_failures']);
        }

        public function testAMalformedIdIsRefusedRatherThanGuessedAt(): void
        {
            $GLOBALS['image_endpoint_caps'][Capabilities::REVIEW] = true;

            $this->assertRefused(404, fn () => $this->endpoint()->handleRequest());
        }

        /**
         * @return iterable<string, array{mixed}>
         */
        public static function malformedIds(): iterable
        {
            yield 'a path traversal' => ['../../../wp-config.php'];
            yield 'a leading sign' => ['-1'];
            yield 'zero' => ['0'];
            yield 'a decimal' => ['1.5'];
            yield 'an array' => [['11']];
            yield 'null' => [null];
        }

        #[DataProvider('malformedIds')]
        public function testEveryMalformedIdIsRefused(mixed $raw): void
        {
            $GLOBALS['image_endpoint_caps'][Capabilities::REVIEW] = true;
            $_GET[AttachmentImageEndpoint::ID_PARAM] = $raw;

            $this->assertRefused(404, fn () => $this->endpoint()->handleRequest());
        }

        public function testANonceForOneImageDoesNotOpenAnother(): void
        {
            $GLOBALS['image_endpoint_caps'][Capabilities::REVIEW] = true;
            $_GET[AttachmentImageEndpoint::ID_PARAM] = '12';

            // A link minted for image 11 must not fetch image 12.
            $GLOBALS['image_endpoint_nonce_action'] = 'adct_pi_attachment_image_11';

            $this->assertRefused(403, fn () => $this->endpoint()->handleRequest());
        }

        public function testAnUnknownButWellFormedImageIsRefused(): void
        {
            $GLOBALS['image_endpoint_caps'][Capabilities::REVIEW] = true;
            $_GET[AttachmentImageEndpoint::ID_PARAM] = '99';
            $GLOBALS['image_endpoint_nonce_action'] = 'adct_pi_attachment_image_99';

            $this->assertRefused(404, fn () => $this->endpoint()->handleRequest());
        }

        public function testAMissingFileOnDiskIsRefusedRatherThanServingNothing(): void
        {
            $GLOBALS['image_endpoint_caps'][Capabilities::REVIEW] = true;
            $_GET[AttachmentImageEndpoint::ID_PARAM] = '11';
            $GLOBALS['image_endpoint_nonce_action'] = 'adct_pi_attachment_image_11';

            // The row is valid but the bytes are gone, so the reviewer gets a
            // clear 404 instead of an empty broken image.
            $this->assertRefused(404, fn () => $this->endpoint()->handleRequest());
        }

        public function testTheImageUrlIsBoundToTheAttachmentItServes(): void
        {
            $url = $this->endpoint()->imageUrl(11);

            self::assertStringContainsString('action=' . AttachmentImageEndpoint::ACTION, $url);
            self::assertStringContainsString(AttachmentImageEndpoint::ID_PARAM . '=11', $url);
            self::assertStringContainsString('admin-post.php', $url);
            self::assertStringContainsString(
                'nonce-for-adct_pi_attachment_image_11',
                $url
            );
        }

        public function testEachImageGetsItsOwnNonce(): void
        {
            $endpoint = $this->endpoint();

            self::assertNotSame(
                $endpoint->imageUrl(11),
                $endpoint->imageUrl(12)
            );
        }

        public function testTheNonceCoversTheIdSoAStoredLinkCannotBeRetargeted(): void
        {
            $url = $this->endpoint()->imageUrl(11);

            // An id of 12 in a link minted for 11 leaves the nonce action
            // unchanged, which the endpoint then refuses.
            self::assertStringContainsString(
                'adct_pi_attachment_image_11',
                $url
            );
        }

        private function writeFile(): void
        {
            mkdir($this->directory, 0o777, true);
            file_put_contents(
                $this->directory . DIRECTORY_SEPARATOR . self::STORAGE_A,
                'not really a jpeg'
            );
        }

        private function endpoint(?string $directory = null): AttachmentImageEndpoint
        {
            return new AttachmentImageEndpoint(
                new KnownImages([
                    $this->image(11, 42, self::STORAGE_A, 'image/jpeg'),
                ]),
                new ProtectedInboundMailStorage($directory ?? $this->directory)
            );
        }

        private function image(int $id, int $messageId, string $storageName, string $mimeType): PreviewableImage
        {
            return new PreviewableImage($id, $messageId, 'poster.jpg', $storageName, $mimeType, 1024);
        }

        private function assertRefused(int $expected, callable $request): void
        {
            try {
                $request();
            } catch (ImageRequestRefused $refused) {
                self::assertSame($expected, $refused->status);

                return;
            }

            self::fail('The image request was served when it should have been refused.');
        }
    }

    /**
     * @implements PreviewableImageRepositoryInterface
     */
    final class KnownImages implements PreviewableImageRepositoryInterface
    {
        /**
         * @param list<PreviewableImage> $images
         */
        public function __construct(private array $images)
        {
        }

        public function findById(int $attachmentId): ?PreviewableImage
        {
            foreach ($this->images as $image) {
                if ($image->attachmentId === $attachmentId) {
                    return $image;
                }
            }

            return null;
        }

        /**
         * @return list<PreviewableImage>
         */
        public function forMessage(int $messageId): array
        {
            return array_values(array_filter(
                $this->images,
                static fn (PreviewableImage $image): bool => $image->messageId === $messageId
            ));
        }
    }
}
