<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Parsing;

use ADCT\ParishIntake\Core\Parsing\Stages\AiEnrichmentStage;
use ADCT\ParishIntake\Core\Parsing\Stages\EventTypeClassificationStage;
use ADCT\ParishIntake\Core\Parsing\EventTypeClassifier;
use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\ParseContext;
use ADCT\ParishIntake\Core\Parsing\ParseResult;
use ADCT\ParishIntake\Core\Parsing\PipelineFactory;
use ADCT\ParishIntake\Core\Ports\AiProviderInterface;
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

    public function testBulletinCandidatesAreClassifiedFromTheirOwnSourceBlocks(): void
    {
        $message = new Message(
            'email',
            'synthetic-bulletin',
            'sender@example.test',
            'Parish office',
            'October bulletin',
            <<<'TEXT'
Parish: Fictional Parish
OCTOBER 2026
Venue: Fictional Parish Hall

EVENTS
Sat 5 - Community gathering at 16:00. The Mass follows the event.
Sat 12 - Community gathering at 16:00. Join us for a retreat.
TEXT
        );

        $candidates = (new PipelineFactory())->create()->parseAll($message)->getCandidates();

        self::assertCount(2, $candidates);
        self::assertSame('liturgy-mass', $candidates[0]->getField('event_type'));
        self::assertSame('spiritual', $candidates[1]->getField('event_type'));
        self::assertStringNotContainsString('retreat', $candidates[0]->getSourceSnippet());
        self::assertStringNotContainsString('Mass', $candidates[1]->getSourceSnippet());
    }

    /** @return array<string, array{string, string}> */
    public static function aiRecoveredFields(): array
    {
        return [
            'AI-recovered title' => ['Parish retreat', 'A fictional parish event.'],
            'AI-recovered description' => ['Community gathering', 'Join us for a retreat.'],
        ];
    }

    #[DataProvider('aiRecoveredFields')]
    public function testClassificationUsesFinalAiEnrichedFields(string $title, string $description): void
    {
        $provider = new class($title, $description) implements AiProviderInterface {
            public function __construct(private string $title, private string $description)
            {
            }

            public function name(): string
            {
                return 'fixture-ai';
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function enrich(Message $message, ParseResult $result): array
            {
                return [
                    'title' => $this->title,
                    'description' => $this->description,
                ];
            }
        };
        $message = new Message(
            'email',
            'synthetic-ai-event',
            'sender@example.test',
            'Parish office',
            '',
            'A fictional event announcement.'
        );
        $context = new ParseContext(['ai_enabled' => true]);
        $context->setRuntimeValue('block_source_text', 'A fictional event announcement.');
        $result = (new AiEnrichmentStage($provider))->process($message, new ParseResult(), $context);

        $result = (new EventTypeClassificationStage(new EventTypeClassifier()))
            ->process($message, $result, $context);

        self::assertSame('spiritual', $result->getField('event_type'));
        self::assertSame('keyword', $result->getField('event_type_source'));
        self::assertContains('title', $result->getAiFieldsFilled());
        self::assertContains('description', $result->getAiFieldsFilled());
    }

    public function testClassificationPreservesAnExplicitlyAssignedEventType(): void
    {
        $message = new Message(
            'email',
            'synthetic-admin-event',
            'sender@example.test',
            'Parish office',
            'A special event',
            'Mass will be celebrated.'
        );
        $context = new ParseContext();
        $context->setRuntimeValue('block_source_text', 'Mass will be celebrated.');
        $result = new ParseResult();
        $result->setField('event_type', 'fundraising');
        $result->setField('event_type_source', 'admin');

        $result = (new EventTypeClassificationStage(new EventTypeClassifier()))
            ->process($message, $result, $context);

        self::assertSame('fundraising', $result->getField('event_type'));
        self::assertSame('admin', $result->getField('event_type_source'));
        self::assertSame([], $result->getStrategies());
    }

    public function testPipelineEnrichesBeforeClassifyingEventType(): void
    {
        $provider = new class implements AiProviderInterface {
            public ?string $eventTypeBeforeEnrichment = null;

            public function name(): string
            {
                return 'fixture-ai';
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function enrich(Message $message, ParseResult $result): array
            {
                $eventType = $result->getField('event_type');
                $this->eventTypeBeforeEnrichment = is_string($eventType) ? $eventType : null;

                return [];
            }
        };
        $message = new Message(
            'email',
            'synthetic-ai-order',
            'sender@example.test',
            'Parish office',
            'Community gathering',
            'Join us on Saturday 10 October 2026 at 16:00.'
        );

        $candidate = (new PipelineFactory())->create([
            'ai_provider' => $provider,
            'ai_enabled' => true,
            'ai_threshold' => 1.0,
        ])->parseAll($message)->getCandidates()[0];

        self::assertNull($provider->eventTypeBeforeEnrichment);
        self::assertSame('other', $candidate->getField('event_type'));
        self::assertSame('keyword', $candidate->getField('event_type_source'));
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
