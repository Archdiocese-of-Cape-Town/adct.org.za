<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Parsing;

final class EventTypeClassifier
{
    /** @var array<string, list<string>> */
    public const DEFAULT_KEYWORDS = [
        'social' => ['social', 'parish picnic', 'community meal', 'fete'],
        'spiritual' => ['retreat', 'prayer evening', 'adoration', 'novena'],
        'formation' => ['formation', 'catechesis', 'workshop', 'conference'],
        'liturgy-mass' => ['mass', 'liturgy', 'eucharist', 'vigil'],
        'youth' => ['youth', 'young adults', 'teenagers'],
        'outreach' => ['outreach', 'food drive', 'volunteer', 'charity'],
        'fundraising' => ['fundraiser', 'fundraising', 'raffle', 'bake sale'],
        'meeting' => ['meeting', 'parish council', 'committee'],
        'pilgrimage' => ['pilgrimage', 'pilgrim'],
        'other' => [],
    ];

    /** @param array<string, list<string>> $keywords */
    public function __construct(private array $keywords = self::DEFAULT_KEYWORDS)
    {
    }

    /**
     * @return array{slug: string, confidence: float}
     */
    public function classify(string $title, string $body): array
    {
        $scores = [];
        foreach ($this->keywords as $slug => $phrases) {
            if ($slug === 'other') {
                continue;
            }
            $score = 0;
            foreach ($phrases as $phrase) {
                $phrase = trim($phrase);
                if ($phrase === '') {
                    continue;
                }
                $pattern = '~(?<![\p{L}\p{N}])' . str_replace(' ', '\s+', preg_quote($phrase, '~'))
                    . '(?![\p{L}\p{N}])~iu';
                if (preg_match($pattern, $title) === 1) {
                    $score += 2;
                }
                if (preg_match($pattern, $body) === 1) {
                    $score++;
                }
            }
            if ($score > 0) {
                $scores[$slug] = $score;
            }
        }

        if ($scores === []) {
            return ['slug' => 'other', 'confidence' => 0.2];
        }
        $best = max($scores);
        $winners = array_keys(array_filter($scores, static fn (int $score): bool => $score === $best));
        return count($winners) === 1
            ? ['slug' => $winners[0], 'confidence' => 0.85]
            : ['slug' => 'other', 'confidence' => 0.2];
    }
}
