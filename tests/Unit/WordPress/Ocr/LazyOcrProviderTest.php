<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Ocr {
    use ADCT\ParishIntake\Core\Ocr\OcrExtractionResult;
    use ADCT\ParishIntake\Core\Ports\OcrProviderInterface;
    use ADCT\ParishIntake\WordPress\Ocr\LazyOcrProvider;
    use PHPUnit\Framework\TestCase;
    use RuntimeException;
    use Throwable;

    final class LazyOcrProviderTest extends TestCase
    {
        public function testNothingIsBuiltUntilTheProviderIsAskedForSomething(): void
        {
            $built = 0;

            new LazyOcrProvider(function () use (&$built): OcrProviderInterface {
                $built++;

                return new StubOcrProvider();
            });

            self::assertSame(0, $built, 'the plugin constructor must not resolve options or secrets');
        }

        public function testTheFirstUseBuildsTheProviderOnceAndReusesIt(): void
        {
            $built = 0;
            $provider = new LazyOcrProvider(function () use (&$built): OcrProviderInterface {
                $built++;

                return new StubOcrProvider();
            });

            $provider->isAvailable();
            $provider->extractText('/tmp/poster.png', 20);

            self::assertSame(1, $built);
        }

        public function testABuiltProviderIsReachedThroughTheWrapper(): void
        {
            $inner = new StubOcrProvider();
            $inner->extractedPath = '/srv/posters/retreat.png';
            $inner->timeoutSeen = 20;

            $provider = new LazyOcrProvider(static fn (): OcrProviderInterface => $inner);

            self::assertTrue($provider->isAvailable());
            self::assertSame(
                OcrExtractionResult::STATUS_EXTRACTED,
                $provider->extractText('/srv/posters/retreat.png', 20)->status
            );
            self::assertSame('/srv/posters/retreat.png', $inner->extractedPath);
            self::assertSame(20, $inner->timeoutSeen);
        }

        public function testAnUnavailableBuiltProviderIsReportedAsUnavailable(): void
        {
            $inner = new StubOcrProvider();
            $inner->available = false;

            self::assertFalse((new LazyOcrProvider(static fn (): OcrProviderInterface => $inner))->isAvailable());
        }

        public function testAFactoryThatThrowsDegradesToNoOcrRatherThanFailingTheMessage(): void
        {
            $provider = new LazyOcrProvider(static function (): OcrProviderInterface {
                throw new RuntimeException('An unreadable setting.');
            });

            self::assertFalse($provider->isAvailable());
            self::assertSame(
                OcrExtractionResult::STATUS_NOT_CONFIGURED,
                $provider->extractText('/srv/posters/retreat.png', 20)->status
            );
        }

        public function testAThrowingFactoryIsNotRerunForEveryPosterInABatch(): void
        {
            $built = 0;
            $provider = new LazyOcrProvider(function () use (&$built): OcrProviderInterface {
                $built++;
                throw new RuntimeException('An unreadable setting.');
            });

            $provider->extractText('/srv/posters/one.png', 20);
            $provider->extractText('/srv/posters/two.png', 20);
            $provider->extractText('/srv/posters/three.png', 20);

            self::assertSame(1, $built);
        }

        public function testAFactoryThatReturnsTheWrongThingIsTreatedAsNoOcr(): void
        {
            $provider = new LazyOcrProvider(static fn (): mixed => new \stdClass());

            self::assertFalse($provider->isAvailable());
            self::assertSame(
                OcrExtractionResult::STATUS_NOT_CONFIGURED,
                $provider->extractText('/srv/posters/retreat.png', 20)->status
            );
        }

        public function testAnErrorRaisedInsideTheFactoryIsAlsoSwallowed(): void
        {
            $provider = new LazyOcrProvider(static function (): OcrProviderInterface {
                throw new \Error('A broken adapter.');
            });

            self::assertFalse($provider->isAvailable());
        }
    }

    final class StubOcrProvider implements OcrProviderInterface
    {
        public bool $available = true;

        public ?string $extractedPath = null;

        public ?int $timeoutSeen = null;

        public function isAvailable(): bool
        {
            return $this->available;
        }

        public function extractText(string $filePath, int $timeoutSeconds): OcrExtractionResult
        {
            $this->extractedPath = $filePath;
            $this->timeoutSeen = $timeoutSeconds;

            return OcrExtractionResult::extracted('EXAMPLE PARISH RETREAT');
        }
    }
}