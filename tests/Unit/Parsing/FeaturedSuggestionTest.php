<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Parsing;

use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\PipelineFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FeaturedSuggestionTest extends TestCase
{
    public static function notices(): array
    {
        return [
            'conference' => ['A conference', true],
            'pilgrimage' => ['A pilgrimage', true],
            'jubilee' => ['A jubilee celebration', true],
            'ordination' => ['An ordination', true],
            'routine' => ['A parish meeting', false],
            'recurring conference' => ['A conference every first Friday', false],
        ];
    }

    #[DataProvider('notices')]
    public function testOnceOffKeywordIsOnlySuggestedOnNonRecurringEvents(string $subject, bool $featured): void
    {
        $message = new Message(
            'email',
            'featured-example',
            'notice@example.test',
            'Example Parish',
            $subject,
            $subject . ' on 12 October 2026 at 10:00.'
        );
        $candidate = (new PipelineFactory())->create()->parse($message)->toArray();

        self::assertSame($featured, $candidate['fields']['featured'] ?? false);
        self::assertContains('featured_suggestion', $candidate['strategies']);
    }
}
