<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Admin;

use ADCT\ParishIntake\Core\Events\RRulePresetMapper;
use ADCT\ParishIntake\WordPress\Admin\CandidateEditForm;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The form offers presets with fixed vocabularies. They must stay identical to
 * what `RRulePresetMapper` accepts, or every recurrence a reviewer chooses is
 * rejected on save.
 */
final class CandidateEditFormTest extends TestCase
{
    public function testEveryOfferedPresetIsAcceptedByTheMapper(): void
    {
        $mapper = new RRulePresetMapper();
        $ordinals = $this->constant('ORDINALS');
        $weekdays = $this->constant('WEEKDAYS');

        foreach (array_keys($this->constant('PRESETS')) as $preset) {
            $rule = $mapper->toRRule(
                $preset,
                'TH',
                '1',
                '5',
                'FREQ=MONTHLY;BYMONTHDAY=9'
            );

                    // "none" means no rule at all, so a null rule is the right answer
                    // there; every other preset must produce a usable RRULE.
                    if ($preset === 'none') {
                        self::assertNull($rule, 'The "none" preset must produce no rule.');
                        continue;
                    }

                    self::assertIsString($rule, 'The mapper must accept the "' . $preset . '" preset.');
                }

        foreach (array_keys($ordinals) as $ordinal) {
            self::assertIsString(
                        $mapper->toRRule('monthly_ordinal', 'TH', (string) $ordinal),
                'The mapper must accept the "' . $ordinal . '" ordinal.'
            );
        }

        foreach (array_keys($weekdays) as $weekday) {
            self::assertIsString(
                $mapper->toRRule('weekly', $weekday),
                'The mapper must accept the "' . $weekday . '" weekday.'
            );
        }
    }

    public function testTheFormOffersExactlyTheMapperVocabulary(): void
    {
        self::assertSame(
            ['none', 'weekly', 'monthly_ordinal', 'monthly_day', 'custom'],
            array_keys($this->constant('PRESETS'))
        );
        self::assertSame(
            ['1', '2', '3', '4', '-1'],
                    array_map(strval(...), array_keys($this->constant('ORDINALS')))
        );
    }

    /**
     * @return array<string, string>
     */
    private function constant(string $name): array
    {
        $value = (new ReflectionClass(CandidateEditForm::class))->getConstant($name);
        self::assertIsArray($value, $name . ' must be an array constant.');

        return $value;
    }
}