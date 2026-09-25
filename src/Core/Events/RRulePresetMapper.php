<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Events;

use InvalidArgumentException;

final class RRulePresetMapper
{
    private const WEEKDAYS = [
        'MO',
        'TU',
        'WE',
        'TH',
        'FR',
        'SA',
        'SU',
    ];

    private const MONTHLY_ORDINALS = [
        '1',
        '2',
        '3',
        '4',
        '-1',
    ];

    public function __construct(private ?RRuleValidator $validator = null)
    {
        $this->validator ??= new RRuleValidator();
    }

    public function toRRule(
        string $preset,
        string $weekday = 'MO',
        string $ordinal = '1',
        string $monthDay = '1',
        string $customRule = ''
    ): ?string {
        $weekday = strtoupper($weekday);

        return match ($preset) {
            'none' => null,
            'weekly' => $this->weeklyRule($weekday),
            'monthly_ordinal' => $this->monthlyOrdinalRule($weekday, $ordinal),
            'monthly_day' => $this->monthlyDayRule($monthDay),
            'custom' => $this->customRule($customRule),
            default => throw new InvalidArgumentException('Choose a supported recurrence preset.'),
        };
    }

    /**
     * @return array{
     *     preset: string,
     *     weekday: string,
     *     ordinal: string,
     *     month_day: string,
     *     custom_rule: string
     * }
     */
    public function fromRRule(?string $rule): array
    {
        $fallback = [
            'preset' => 'none',
            'weekday' => 'MO',
            'ordinal' => '1',
            'month_day' => '1',
            'custom_rule' => '',
        ];

        if ($rule === null || trim($rule) === '') {
            return $fallback;
        }

        $validation = $this->validator->validate($rule);

        if (! $validation->isValid()) {
            return array_merge($fallback, [
                'preset' => 'custom',
                'custom_rule' => trim($rule),
            ]);
        }

        $parts = $validation->parts;

        if (
            count($parts) === 2
            && ($parts['FREQ'] ?? '') === 'WEEKLY'
            && isset($parts['BYDAY'])
            && in_array($parts['BYDAY'], self::WEEKDAYS, true)
        ) {
            return array_merge($fallback, [
                'preset' => 'weekly',
                'weekday' => $parts['BYDAY'],
            ]);
        }

        if (
            count($parts) === 2
            && ($parts['FREQ'] ?? '') === 'MONTHLY'
            && isset($parts['BYDAY'])
            && preg_match('/^(-?[1-4])?(MO|TU|WE|TH|FR|SA|SU)$/', $parts['BYDAY'], $matches) === 1
            && ($matches[1] ?? '') !== ''
            && in_array($matches[1] ?: '1', self::MONTHLY_ORDINALS, true)
        ) {
            return array_merge($fallback, [
                'preset' => 'monthly_ordinal',
                'weekday' => $matches[2],
                'ordinal' => $matches[1] ?: '1',
            ]);
        }

        if (
            count($parts) === 2
            && ($parts['FREQ'] ?? '') === 'MONTHLY'
            && isset($parts['BYMONTHDAY'])
            && preg_match('/^\d{1,2}$/', $parts['BYMONTHDAY']) === 1
            && (int) $parts['BYMONTHDAY'] >= 1
            && (int) $parts['BYMONTHDAY'] <= 31
        ) {
            return array_merge($fallback, [
                'preset' => 'monthly_day',
                'month_day' => (string) (int) $parts['BYMONTHDAY'],
            ]);
        }

        return array_merge($fallback, [
            'preset' => 'custom',
            'custom_rule' => $validation->normalizedRule ?? trim($rule),
        ]);
    }

    private function weeklyRule(string $weekday): string
    {
        if (! in_array($weekday, self::WEEKDAYS, true)) {
            throw new InvalidArgumentException('Choose a valid weekday for the weekly recurrence.');
        }

        return 'FREQ=WEEKLY;BYDAY=' . $weekday;
    }

    private function monthlyOrdinalRule(string $weekday, string $ordinal): string
    {
        if (! in_array($weekday, self::WEEKDAYS, true) || ! in_array($ordinal, self::MONTHLY_ORDINALS, true)) {
            throw new InvalidArgumentException('Choose a supported monthly weekday and ordinal.');
        }

        return 'FREQ=MONTHLY;BYDAY=' . $ordinal . $weekday;
    }

    private function monthlyDayRule(string $monthDay): string
    {
        if (preg_match('/^\d{1,2}$/', $monthDay) !== 1 || (int) $monthDay < 1 || (int) $monthDay > 31) {
            throw new InvalidArgumentException('Choose a day of the month from 1 to 31.');
        }

        return 'FREQ=MONTHLY;BYMONTHDAY=' . (string) (int) $monthDay;
    }

    private function customRule(string $rule): string
    {
        $rule = trim($rule);

        if ($rule === '') {
            throw new InvalidArgumentException('Enter an RRULE for the custom recurrence.');
        }

        return $rule;
    }
}
