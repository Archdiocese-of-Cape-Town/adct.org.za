<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Auth;

use InvalidArgumentException;

final class ActionTokenPreview
{
    /**
     * @param list<string> $details
     */
    public function __construct(
        public readonly string $title,
        public readonly string $summary,
        public readonly string $submitLabel,
        public readonly array $details = [],
        public readonly bool $actionable = true,
        public readonly array $formFields = []
    ) {
        if (trim($title) === '' || trim($summary) === '' || trim($submitLabel) === '') {
            throw new InvalidArgumentException('An action token preview needs display text.');
        }

        foreach ($details as $detail) {
            if (! is_string($detail)) {
                throw new InvalidArgumentException('Action token preview details must be text.');
            }
        }
    }
}
