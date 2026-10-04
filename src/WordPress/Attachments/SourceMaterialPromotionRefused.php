<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Attachments;

use RuntimeException;

/**
 * A refusal this plugin raised about a source-material promotion, phrased for
 * the person who pressed the button.
 *
 * A distinct class rather than a plain RuntimeException because the caller has
 * to tell two things apart: a refusal like "That file cannot be published as
 * source material.", which is worth showing to a parish secretary verbatim, and
 * a failure from inside WordPress — "Image resizing failed." — which is not.
 * Comparing exception messages to tell them apart would put a WordPress
 * internal message on an admin screen.
 */
final class SourceMaterialPromotionRefused extends RuntimeException
{
}
