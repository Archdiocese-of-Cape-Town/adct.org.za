<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Attachments;

use ADCT\ParishIntake\Core\Attachments\CandidateSourceImageResolver;
use ADCT\ParishIntake\Core\Attachments\PreviewableImage;
use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
use ADCT\ParishIntake\Core\Ports\CandidateSourceMessageInterface;
use ADCT\ParishIntake\Core\Ports\PreviewableImageRepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * The resolver is what stops an emailed token being used to browse the private
 * attachment store, so these tests are mostly about what it refuses.
 */
final class CandidateSourceImageResolverTest extends TestCase
{
    private const STORAGE_A = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90.jpg';
    private const STORAGE_B = '0f1e2d3c4b5a69788796a5b4c3d2e1f00f1e2d3c4b5a69788796a5b4c3d2e1f0.png';

    public function testItResolvesTheFirstImageOfTheMessagesOwnCandidate(): void
    {
        $first = new PreviewableImage(11, 42, 'first.jpg', self::STORAGE_A, 'image/jpeg', 100);
        $second = new PreviewableImage(12, 42, 'second.png', self::STORAGE_B, 'image/png', 100);

        $resolver = new CandidateSourceImageResolver(
            new ResolverImages([42 => [$first, $second]]),
            new ResolverCandidates([5 => 42])
        );

        $image = $resolver->forBinding($this->binding(ActionTokenPurpose::APPROVE_EVENT, 5));

        self::assertSame($first, $image);
    }

    public function testItRefusesABindingForSomethingOtherThanAnEventCandidate(): void
    {
        $image = new PreviewableImage(11, 42, 'first.jpg', self::STORAGE_A, 'image/jpeg', 100);

        $resolver = new CandidateSourceImageResolver(
            new ResolverImages([42 => [$image]]),
            new ResolverCandidates([5 => 42])
        );

        $binding = new ActionTokenBinding(
            ActionTokenPurpose::APPROVE_EVENT,
            'inbound_message',
            5,
            'parish@example.test'
        );

        self::assertNull($resolver->forBinding($binding));
    }

    public function testItResolvesNothingForACandidateWithNoSourceMessage(): void
    {
        $image = new PreviewableImage(11, 42, 'first.jpg', self::STORAGE_A, 'image/jpeg', 100);

        $resolver = new CandidateSourceImageResolver(
            new ResolverImages([42 => [$image]]),
            new ResolverCandidates([])
        );

        // A manually entered or portal-entered candidate has no message, so it
        // correctly has no poster and therefore no OCR button.
        self::assertNull($resolver->forBinding($this->binding(
            ActionTokenPurpose::APPROVE_EVENT,
            999
        )));
    }

    public function testItResolvesNothingWhenTheMessageHadNoReadableImage(): void
    {
        $resolver = new CandidateSourceImageResolver(
            new ResolverImages([]),
            new ResolverCandidates([5 => 42])
        );

        self::assertNull($resolver->forBinding($this->binding(
            ActionTokenPurpose::APPROVE_EVENT,
            5
        )));
    }

    public function testEachCandidateOnlyEverReachesItsOwnMessage(): void
    {
        $mine = new PreviewableImage(11, 42, 'mine.jpg', self::STORAGE_A, 'image/jpeg', 100);
        $theirs = new PreviewableImage(21, 99, 'theirs.png', self::STORAGE_B, 'image/png', 100);

        $resolver = new CandidateSourceImageResolver(
            new ResolverImages([42 => [$mine], 99 => [$theirs]]),
            new ResolverCandidates([5 => 42, 6 => 99])
        );

        $resolved = $resolver->forBinding($this->binding(ActionTokenPurpose::APPROVE_EVENT, 5));

        self::assertNotNull($resolved);
        self::assertSame(42, $resolved->messageId);
        self::assertNotSame(99, $resolved->messageId);
    }

    private function binding(ActionTokenPurpose $purpose, int $subjectId): ActionTokenBinding
    {
        return new ActionTokenBinding($purpose, 'event_candidate', $subjectId, 'parish@example.test');
    }
}

/**
 * @implements PreviewableImageRepositoryInterface
 */
final class ResolverImages implements PreviewableImageRepositoryInterface
{
    /**
     * @param array<int, list<PreviewableImage>> $byMessage
     */
    public function __construct(private array $byMessage)
    {
    }

    public function findById(int $attachmentId): ?PreviewableImage
    {
        foreach ($this->byMessage as $images) {
            foreach ($images as $image) {
                if ($image->attachmentId === $attachmentId) {
                    return $image;
                }
            }
        }

        return null;
    }

    /**
     * @return list<PreviewableImage>
     */
    public function forMessage(int $messageId): array
    {
        return $this->byMessage[$messageId] ?? [];
    }
}

/**
 * @implements CandidateSourceMessageInterface
 */
final class ResolverCandidates implements CandidateSourceMessageInterface
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
