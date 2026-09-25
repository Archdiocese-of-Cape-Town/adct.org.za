<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Auth;

use ADCT\ParishIntake\Core\Ports\ActionTokenRateLimitKeyProviderInterface;
use RuntimeException;

final class WordPressActionTokenRateLimitKeyProvider implements ActionTokenRateLimitKeyProviderInterface
{
    public function getKey(): string
    {
        if (! function_exists('wp_salt')) {
            throw new RuntimeException('WordPress authentication salts are not available.');
        }

        return \wp_salt('auth');
    }
}
