<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Parsing;

use ADCT\ParishIntake\Core\Parsing\EventTypeClassifier;
use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\PipelineFactory;
use ADCT\ParishIntake\Core\Ports\EventTypeKeywordProviderInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EventTypeClassifierTest extends TestCase
{
    /** @return array<string, array{string, string, string}> */
    public static function examples(): array
    {
        return [
            'liturgy' => ['Sunday Mass', 'Join us for Mass.', 'liturgy-mass'],
            'spiritual' => ['Parish retreat', 'A day of prayer.', 'spiritual'],
            'formation' => ['Formation workshop', 'All welcome.', 'formation'],
            'social' => ['Parish picnic', 'Bring your lunch.', 'social'],
            'youth' => ['Youth gathering', 'All welcome.', 'youth'],
            'outreach' => ['Food drive', 'Bring donations.', 'outreach'],
            'fundraiser' => ['Parish fundraiser', 'All welcome.', 'fundraising'],
            'pilgrimage' => ['Pilgrimage to the shrine', 'All welcome.', 'pilgrimage'],
            'meeting' => ['Parish council meeting', 'All welcome.', 'meeting'],
            'tie' => ['Mass and retreat', 'Join us.', 'other'],
            'no match' => ['A special event', 'Join us.', 'other'],
            'no substring' => ['Massive celebration', 'No parish type is stated.', 'other'],
            'phrase with extra spacing' => ['Parish   picnic', 'Bring your lunch.', 'social'],
        ];
    }

    #[DataProvider('examples')]
    public function testDeterministicClassification(string $title, string $body, string $expected): void
    {
        $result = (new EventTypeClassifier())->classify($title, $body);
        self::assertSame($expected, $result['slug']);
        self::assertSame($expected === 'other' ? 0.2 : 0.85, $result['confidence']);
    }

    public function testTitleOutweighsBodyAndRepeatingOneKeywordDoesNotInflateScore(): void
    {
        $result = (new EventTypeClassifier())->classify('Youth gathering', 'Retreat retreat retreat');
        self::assertSame('youth', $result['slug']);
    }

    public function testEditedAndCustomTermKeywordsTakeEffectInNextPipelineWithoutCodeChange(): void
    {
        $provider = new class implements EventTypeKeywordProviderInterface {
            public array $lists = [
                'pilgrimage' => ['journey'],
                'custom-type' => ['special gathering'],
                'other' => [],
            ];

            public function keywordLists(): array
            {
                return $this->lists;
            }
        };
        $factory = new PipelineFactory(null, null, $provider);
        $parse = static fn (string $subject) => $factory->create()->parse(new Message(
            'email',
            'synthetic-id',
            'sender@example.test',
            'Parish office',
            $subject,
            'Join us on 12 Oct 2026 at 19:00 for this event.'
        ));

        self::assertSame('pilgrimage', $parse('Journey')->getField('event_type'));
        self::assertSame('custom-type', $parse('Special gathering')->getField('event_type'));
        $provider->lists['pilgrimage'] = ['walk'];
        self::assertSame('other', $parse('Journey')->getField('event_type'));
        self::assertSame('pilgrimage', $parse('Walk')->getField('event_type'));
        self::assertSame('keyword', $parse('Walk')->getField('event_type_source'));
        self::assertSame(0.2, $parse('Journey')->getField('event_type_confidence'));
    }
}
