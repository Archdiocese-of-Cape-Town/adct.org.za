<?php

namespace ADCT\ParishIntake\WordPress\Ai;

use ADCT\ParishIntake\Core\Ports\AiCallGateInterface;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\WordPress\Database\WordPressActionTokenRateLimitStore;
use DateTimeZone;

final class WordPressAiCallGate implements AiCallGateInterface
{
    private const COOLDOWN_OPTION = 'adct_parish_intake_ai_backoff_until';

    public function __construct(
        private WordPressActionTokenRateLimitStore $limits,
        private ClockInterface $clock,
        private int $dailyCap = 50
    ) {
        if ($dailyCap < 1 || $dailyCap > 1000) {
            throw new \InvalidArgumentException('AI daily cap must be between 1 and 1000.');
        }
    }

    public function reserve(): bool
    {
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        if ((int) get_option(self::COOLDOWN_OPTION, 0) > $now->getTimestamp()) {
            return false;
        }

        return $this->limits->consume(
            hash('sha256', 'adct_pi_ai_daily_calls'),
            $now->setTime(0, 0),
            $this->dailyCap
        );
    }

    public function backOff(int $seconds): void
    {
        $until = $this->clock->now()->getTimestamp() + $seconds;
        update_option(self::COOLDOWN_OPTION, max((int) get_option(self::COOLDOWN_OPTION, 0), $until), false);
    }
}
