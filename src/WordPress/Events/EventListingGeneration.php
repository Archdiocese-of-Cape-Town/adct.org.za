<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Events;

use RuntimeException;

final class EventListingGeneration
{
    public const OPTION = 'adct_pi_event_listing_generation';

    public function current(): string
    {
        $value = get_option(self::OPTION, '0');
        if (! is_string($value)
            || ($value !== '0' && preg_match('/^[a-f0-9]{32}$/D', $value) !== 1)) {
            throw new RuntimeException('The event listing cache generation is invalid.');
        }
        return $value;
    }

    public function bump(): string
    {
        $generation = bin2hex(random_bytes(16));
        if (! update_option(self::OPTION, $generation, false)) {
            throw new RuntimeException('The event listing cache generation could not be saved.');
        }
        return $generation;
    }
}
