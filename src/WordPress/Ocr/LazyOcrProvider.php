<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Ocr;

use ADCT\ParishIntake\Core\Ocr\OcrExtractionResult;
use ADCT\ParishIntake\Core\Ports\OcrProviderInterface;
use Closure;
use Throwable;

/**
 * Builds the real provider the first time it is asked for anything.
 *
 * The plugin constructor runs before WordPress is loaded, so it cannot build an
 * adapter that reads options or resolves secrets. The provider is therefore
 * wrapped in this until a message actually arrives, which is the first moment
 * those collaborators are safe to touch and the first moment the operator's
 * settings can be read without being frozen at activation.
 *
 * A factory that throws is treated as "not available" rather than propagated:
 * an unreadable setting must degrade to the no-OCR path like every other OCR
 * failure, never to a failed message.
 */
final class LazyOcrProvider implements OcrProviderInterface
{
    /** @var Closure(): OcrProviderInterface */
    private readonly Closure $factory;

    /** @var OcrProviderInterface|null */
        private ?OcrProviderInterface $provider = null;

        private bool $buildFailed = false;

    /**
     * @param Closure(): OcrProviderInterface $factory
     */
    public function __construct(Closure $factory)
    {
        $this->factory = $factory;
    }

    public function isAvailable(): bool
    {
        $provider = $this->resolve();

        return $provider !== null && $provider->isAvailable();
    }

    public function extractText(string $filePath, int $timeoutSeconds): OcrExtractionResult
    {
        $provider = $this->resolve();

        if ($provider === null) {
            return OcrExtractionResult::notConfigured();
        }

        return $provider->extractText($filePath, $timeoutSeconds);
    }

    /**
     * A failed build is remembered for this request so one broken setting does
     * not re-run the factory for every poster in a batch.
     */
    private function resolve(): ?OcrProviderInterface
    {
        if ($this->provider !== null) {
            return $this->provider;
        }

        if ($this->buildFailed) {
            return null;
        }

        try {
            $provider = ($this->factory)();
        } catch (Throwable) {
                    $this->buildFailed = true;

            return null;
        }

        if (! $provider instanceof OcrProviderInterface) {
                    $this->buildFailed = true;

                    return null;
                }

        $this->provider = $provider;

        return $provider;
    }
}