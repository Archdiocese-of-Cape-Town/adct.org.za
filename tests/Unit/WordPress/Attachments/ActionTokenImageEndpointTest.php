<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Attachments;

use ADCT\ParishIntake\Core\Attachments\CandidateSourceImageResolver;
use ADCT\ParishIntake\Core\Attachments\PreviewableImage;
use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
use ADCT\ParishIntake\Core\Auth\ActionTokenRecord;
use ADCT\ParishIntake\Core\Auth\ActionTokenService;
use ADCT\ParishIntake\Core\Auth\ActionTokenStatus;
use ADCT\ParishIntake\Core\Ports\ActionTokenStoreInterface;
use ADCT\ParishIntake\Core\Ports\CandidateSourceMessageInterface;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\PreviewableImageRepositoryInterface;
use ADCT\ParishIntake\WordPress\Attachments\ActionTokenImageEndpoint;
use ADCT\ParishIntake\WordPress\Ingestion\ProtectedInboundMailStorage;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The emailed token pages are public, so the live action token is the only
 * authorisation there is. These tests cover the ways a token must not reach an
 * image, and the guarantee that looking at one never spends the decision.
 */
final class ActionTokenImageEndpointTest extends TestCase
{
    private const STORAGE_A = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90.jpg';
    private const STORAGE_B = '0f1e2d3c4b5a69788796a5b4c3d2e1f00f1e2d3c4b5a69788796a5b4c3d2e1f0.png';

    private TokenStore $store;
    private FrozenClock $clock;
    private string $directory;

    protected function setUp(): void
    {
        $this->store = new TokenStore();
        $this->clock = new FrozenClock();
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'adct-ocr-' . bin2hex(random_bytes(6));
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

    public function testALiveTokenSeesItsOwnCandidatesImage(): void
    {
        [$endpoint, $service] = $this->endpoint([$this->image(11, 42, self::STORAGE_A, 'image/jpeg')]);

        $allowed = $endpoint->allowedImage($this->token($service), 11);

        self::assertNotNull($allowed);
        self::assertSame(11, $allowed->attachmentId);
        self::assertSame(42, $allowed->messageId);
    }

    public function testATokenCannotReachAnotherCandidatesImage(): void
    {
        [$endpoint, $service] = $this->endpoint([
            $this->image(11, 42, self::STORAGE_A, 'image/jpeg'),
            $this->image(21, 99, self::STORAGE_B, 'image/png'),
        ]);

        $token = $this->token($service);

        // Candidate 5 came from message 42, so image 21 is out of reach however
        // the URL is edited. This is the anti-enumeration control.
        self::assertNull($endpoint->allowedImage($token, 21));
        self::assertNotNull($endpoint->allowedImage($token, 11));
    }

    public function testAnExpiredTokenCannotSeeAnImage(): void
    {
        [$endpoint, $service] = $this->endpoint([$this->image(11, 42, self::STORAGE_A, 'image/jpeg')]);

        $token = $this->token($service);
        $this->clock->travelTo('2026-10-26 12:00:00');

        self::assertSame(ActionTokenStatus::EXPIRED, $service->inspect($token)->status);
        self::assertNull($endpoint->allowedImage($token, 11));
        self::assertNull($endpoint->imageForToken($token));
    }

    public function testAConsumedTokenStillResolvesItsImageForTheRecoveryPage(): void
    {
        // Consuming sets used_at rather than deleting the row, so afterwards the
        // token inspects as USED. The approve/reject recovery flow deliberately
        // re-renders the preview in that state so a reviewer whose button
        // failed can see what they already decided, which means the poster has
        // to be reachable there too.
        [$endpoint, $service] = $this->endpoint([$this->image(11, 42, self::STORAGE_A, 'image/jpeg')]);

        $token = $this->token($service);
        $consumed = $service->consume($token, $this->binding());

        self::assertSame(ActionTokenStatus::CONSUMED, $consumed->status);
        self::assertSame(ActionTokenStatus::USED, $service->inspect($token)->status);
        self::assertNotNull($endpoint->allowedImage($token, 11));
    }

    public function testAUsedTokenStillSeesItsImage(): void
    {
        [$endpoint, $service] = $this->endpoint([$this->image(11, 42, self::STORAGE_A, 'image/jpeg')]);

        $token = $this->token($service);
        $this->store->markUsed(hash('sha256', $token), $this->clock->now());

        self::assertSame(ActionTokenStatus::USED, $service->inspect($token)->status);
        self::assertNotNull($endpoint->allowedImage($token, 11));
    }

    public function testAnUnknownTokenCannotSeeAnImage(): void
    {
        [$endpoint] = $this->endpoint([$this->image(11, 42, self::STORAGE_A, 'image/jpeg')]);

        self::assertNull($endpoint->allowedImage('not-a-real-token', 11));
        self::assertNull($endpoint->imageForToken('not-a-real-token'));
    }

    public function testAnEmptyTokenCannotSeeAnImage(): void
    {
        [$endpoint] = $this->endpoint([$this->image(11, 42, self::STORAGE_A, 'image/jpeg')]);

        self::assertNull($endpoint->allowedImage('', 11));
        self::assertNull($endpoint->imageForToken(''));
    }

    public function testATokenForSomethingOtherThanAnEventCandidateHasNoImage(): void
    {
        [$endpoint, $service] = $this->endpoint([$this->image(11, 42, self::STORAGE_A, 'image/jpeg')]);

        $token = $service->issue(new ActionTokenBinding(
            ActionTokenPurpose::EDIT,
            'event',
            5,
            'parish@example.test'
        ))->token();

        // Only an event_candidate binding maps back to a message, so a token
        // for any other subject type resolves nothing.
        self::assertNull($endpoint->imageForToken($token));
    }

    public function testACandidateWithNoSourceMessageHasNoImage(): void
    {
        [$endpoint, $service] = $this->endpoint([]);

        // Candidate 999 is a manual or portal entry: no message, no poster, and
        // so no OCR button.
        $token = $service->issue(new ActionTokenBinding(
            ActionTokenPurpose::APPROVE_EVENT,
            'event_candidate',
            999,
            'parish@example.test'
        ))->token();

        self::assertNull($endpoint->imageForToken($token));
    }

    public function testAMessageWithNoBrowserReadableImageHasNoImage(): void
    {
        // Image 21 belongs to message 99, not to candidate 5's message 42.
        [$endpoint, $service] = $this->endpoint([
            $this->image(21, 99, self::STORAGE_B, 'image/png'),
        ]);

        self::assertNull($endpoint->imageForToken($this->token($service)));
    }

    public function testANonPositiveAttachmentIdIsRefused(): void
    {
        [$endpoint, $service] = $this->endpoint([$this->image(11, 42, self::STORAGE_A, 'image/jpeg')]);
        $token = $this->token($service);

        self::assertNull($endpoint->allowedImage($token, 0));
        self::assertNull($endpoint->allowedImage($token, -1));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedImageParameters(): iterable
    {
        yield 'a path traversal' => ['../../../wp-config.php'];
        yield 'a trailing query string' => ['11&adct_token=other'];
        yield 'a leading sign' => ['-1'];
        yield 'a decimal' => ['1.5'];
        yield 'hexadecimal' => ['0x0b'];
        yield 'an array' => [['11']];
        yield 'null' => [null];
        yield 'an overlong id' => ['99999999999999999999'];
        yield 'surrounding whitespace' => [' 11 '];
        yield 'an empty string' => [''];
    }

    #[DataProvider('malformedImageParameters')]
    public function testAMalformedImageParameterIsTreatedAsNoRequest(mixed $raw): void
    {
        $_GET[ActionTokenImageEndpoint::IMAGE_PARAM] = $raw;

        try {
            self::assertSame(0, ActionTokenImageEndpoint::requestedAttachmentId());
        } finally {
            unset($_GET[ActionTokenImageEndpoint::IMAGE_PARAM]);
        }
    }

    public function testAMissingImageParameterIsNoRequest(): void
    {
        unset($_GET[ActionTokenImageEndpoint::IMAGE_PARAM]);

        self::assertSame(0, ActionTokenImageEndpoint::requestedAttachmentId());
    }

    public function testAWellFormedImageParameterIsReadAsAnId(): void
    {
        $_GET[ActionTokenImageEndpoint::IMAGE_PARAM] = '11';

        try {
            self::assertSame(11, ActionTokenImageEndpoint::requestedAttachmentId());
        } finally {
            unset($_GET[ActionTokenImageEndpoint::IMAGE_PARAM]);
        }
    }

    public function testViewingAnImageDoesNotSpendTheToken(): void
    {
        [$endpoint, $service] = $this->endpoint([$this->image(11, 42, self::STORAGE_A, 'image/jpeg')]);
        $token = $this->token($service);

        $endpoint->allowedImage($token, 11);
        $endpoint->allowedImage($token, 11);
        $endpoint->imageForToken($token);

        // The reviewer can look as often as they like and still decide once.
        self::assertSame(ActionTokenStatus::VALID, $service->inspect($token)->status);
        self::assertSame(
            ActionTokenStatus::CONSUMED,
            $service->consume($token, $this->binding())->status
        );
    }

    public function testAStoredFileResolvesToItsPath(): void
    {
        $image = $this->image(11, 42, self::STORAGE_A, 'image/jpeg');
        [$endpoint] = $this->endpoint([$image], $this->writeFile(self::STORAGE_A));

        self::assertSame(
            $this->directory . DIRECTORY_SEPARATOR . self::STORAGE_A,
            $endpoint->resolvePath($image)
        );
    }

    public function testAMissingFileResolvesToNoPathRatherThanFailing(): void
    {
        $image = $this->image(11, 42, self::STORAGE_A, 'image/jpeg');
        [$endpoint] = $this->endpoint([$image]);

        self::assertNull($endpoint->resolvePath($image));
    }

    private function writeFile(string $storageName): string
    {
        mkdir($this->directory, 0o777, true);
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . $storageName, 'not really a jpeg');

        return $this->directory;
    }

    private function image(int $attachmentId, int $messageId, string $storageName, string $mimeType): PreviewableImage
    {
        return new PreviewableImage($attachmentId, $messageId, 'poster.jpg', $storageName, $mimeType, 1024);
    }

    private function binding(): ActionTokenBinding
    {
        return new ActionTokenBinding(
            ActionTokenPurpose::APPROVE_EVENT,
            'event_candidate',
            5,
            'parish@example.test'
        );
    }

    private function token(ActionTokenService $service): string
    {
        return $service->issue($this->binding())->token();
    }

    /**
     * @param list<PreviewableImage> $images
     * @return array{ActionTokenImageEndpoint, ActionTokenService}
     */
    private function endpoint(array $images, ?string $directory = null): array
    {
        $service = new ActionTokenService($this->store, $this->clock);
        $repository = new StoredImages($images);

        return [
            new ActionTokenImageEndpoint(
                $service,
                new CandidateSourceImageResolver($repository, new SourceMessages([5 => 42])),
                $repository,
                new ProtectedInboundMailStorage($directory ?? $this->directory)
            ),
            $service,
        ];
    }
}

final class FrozenClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct()
    {
        $this->now = new DateTimeImmutable('2026-09-26 12:00:00', new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function travelTo(string $moment): void
    {
        $this->now = new DateTimeImmutable($moment, new DateTimeZone('UTC'));
    }
}

final class TokenStore implements ActionTokenStoreInterface
{
    /** @var array<string, ActionTokenRecord> */
    private array $records = [];

    public function create(ActionTokenRecord $record): void
    {
        $this->records[$record->tokenHash] = $record;
    }

    public function findByHash(string $tokenHash): ?ActionTokenRecord
    {
        return $this->records[$tokenHash] ?? null;
    }

    public function consume(
        string $tokenHash,
        ActionTokenBinding $binding,
        DateTimeImmutable $now
    ): bool {
        $record = $this->records[$tokenHash] ?? null;

        if ($record === null || $record->usedAt !== null || ! $record->binding->equals($binding)) {
            return false;
        }

        $this->records[$tokenHash] = $this->withUsedAt($record, $now);

        return true;
    }

    public function markUsed(string $tokenHash, DateTimeImmutable $now): void
    {
        $record = $this->records[$tokenHash] ?? null;

        if ($record !== null) {
            $this->records[$tokenHash] = $this->withUsedAt($record, $now);
        }
    }

    private function withUsedAt(ActionTokenRecord $record, DateTimeImmutable $now): ActionTokenRecord
    {
        return new ActionTokenRecord(
            $record->tokenHash,
            $record->binding,
            $record->expiresAt,
            $now,
            $record->createdAt
        );
    }
}

/**
 * @implements PreviewableImageRepositoryInterface
 */
final class StoredImages implements PreviewableImageRepositoryInterface
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

/**
 * @implements CandidateSourceMessageInterface
 */
final class SourceMessages implements CandidateSourceMessageInterface
{
    /**
     * @param array<int, int> $messageIdByCandidate
     */
    public function __construct(private array $messageIdByCandidate)
    {
    }

    public function sourceMessageIdForCandidate(int $candidateId): ?int
    {
        return $this->messageIdByCandidate[$candidateId] ?? null;
    }
}
