<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Ocr;

use ADCT\ParishIntake\Core\Ports\ActionTokenRateLimitStoreInterface;
use ADCT\ParishIntake\Core\Ports\AiCallGateInterface;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Counts and paces the calls this site makes to OCR.space.
 *
 * The same shape as the optional-AI gate, on its own option and its own daily
 * bucket, because OCR and AI are enabled independently and share nothing: a
 * site that has switched AI off may still send posters, and an AI provider that
 * returns a rate limit must not stop a poster from being read.
 *
 * The counter is incremented *before* the call is made, not after it returns.
 * A response that never arrives still consumed a unit of the free tier.
 */
final class WordPressOcrCallGate implements AiCallGateInterface
{
    private const COOLDOWN_OPTION = 'adct_parish_intake_ocr_backoff_until';

    private const DAILY_BUCKET = 'adct_pi_ocr_daily_calls';

    public function __construct(
        private readonly ActionTokenRateLimitStoreInterface $limits,
        private readonly ClockInterface $clock,
        private readonly int $dailyCap = 25,
    ) {
        if ($dailyCap < 1 || $dailyCap > 1000) {
            throw new InvalidArgumentException('OCR daily cap must be between 1 and 1000.');
        }
    }

    public function reserve(): bool
    {
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));

        // A rate limit cools the whole site down rather than just one poster,
        // so the next run does not walk into the same wall.
        if ((int) get_option(self::COOLDOWN_OPTION, 0) > $now->getTimestamp()) {
            return false;
        }

        return $this->limits->consume(
            hash('sha256', self::DAILY_BUCKET),
            $now->setTime(0, 0),
            $this->dailyCap
        );
    }

    public function backOff(int $seconds): void
    {
        $until = $this->clock->now()->getTimestamp() + max(0, $seconds);
        update_option(self::COOLDOWN_OPTION, max((int) get_option(self::COOLDOWN_OPTION, 0), $until), false);
    }
}