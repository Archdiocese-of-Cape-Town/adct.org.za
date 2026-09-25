<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion;

use InvalidArgumentException;

final readonly class AutomatedMailAssessment
{
    public const CLASSIFICATION_NONE = 'none';
    public const CLASSIFICATION_DECLARED = 'declared';
    public const CLASSIFICATION_LIKELY = 'likely';

    /**
     * @param list<string> $signals
     */
    public function __construct(
        public string $classification,
        public array $signals
    ) {
        if (! in_array($classification, [
            self::CLASSIFICATION_NONE,
            self::CLASSIFICATION_DECLARED,
            self::CLASSIFICATION_LIKELY,
        ], true)) {
            throw new InvalidArgumentException('An automated-mail assessment has an unsupported classification.');
        }

        if (! array_is_list($signals) || ($classification === self::CLASSIFICATION_NONE) !== ($signals === [])) {
            throw new InvalidArgumentException('An automated-mail assessment has inconsistent signal details.');
        }

        foreach ($signals as $signal) {
            if (! is_string($signal) || preg_match('/\A[a-z][a-z0-9_]*\z/D', $signal) !== 1) {
                throw new InvalidArgumentException('An automated-mail signal has an invalid identifier.');
            }
        }
    }

    public function blocksConfirmation(): bool
    {
        return $this->classification !== self::CLASSIFICATION_NONE;
    }
}
