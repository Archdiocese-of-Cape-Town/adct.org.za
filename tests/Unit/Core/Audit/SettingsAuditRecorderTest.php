<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Audit;

use ADCT\ParishIntake\Core\Audit\SettingsAuditRecorder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SettingsAuditRecorderTest extends TestCase
{
    public function testAnUntouchedRecorderHasNothingToReport(): void
    {
        $recorder = new SettingsAuditRecorder();

        self::assertFalse($recorder->hasChanges());
        self::assertSame([], $recorder->changes());
        self::assertSame(['changed' => []], $recorder->toDetails());
    }

    public function testRecordsOnlyTheSettingsThatChanged(): void
    {
        $recorder = new SettingsAuditRecorder();

        $recorder->record('adct_parish_intake_threshold', '0.55', '0.55');
        $recorder->record('adct_parish_intake_threshold', '0.55', '0.80');
        $recorder->record('adct_parish_intake_retention_days', '24', '24');
        $recorder->record('adct_parish_intake_retention_days', '24', '36');

        self::assertTrue($recorder->hasChanges());
        self::assertSame([
            ['option' => 'adct_parish_intake_threshold', 'before' => '0.55', 'after' => '0.80'],
            ['option' => 'adct_parish_intake_retention_days', 'before' => '24', 'after' => '36'],
        ], $recorder->changes());
    }

    #[DataProvider('equivalentValues')]
        public function testTreatsEqualValuesAsUnchanged(mixed $before, mixed $after): void
    {
        $recorder = new SettingsAuditRecorder();

        $recorder->record('setting', $before, $after);

        self::assertFalse($recorder->hasChanges());
    }

    /**
     * @return array<string, array{mixed, mixed}>
     */
    public static function equivalentValues(): array
    {
        return [
            'identical strings' => ['on', 'on'],
            'a numeric string and its integer twin' => ['12', 12],
            'an integer and a float' => [12, 12.0],
            'false and the empty string' => [false, ''],
            'null and the empty string' => [null, ''],
            'null twice' => [null, null],
            'two empty arrays' => [[], []],
        ];
    }

    #[DataProvider('describableValues')]
        public function testDescribesTheValueInWords(mixed $value, string $expected): void
    {
        $recorder = new SettingsAuditRecorder();

        $recorder->record('setting', 'before', $value);

        self::assertSame($expected, $recorder->changes()[0]['after']);
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function describableValues(): array
    {
        return [
            'a string' => ['on', 'on'],
            'true' => [true, 'yes'],
            'false' => [false, 'no'],
            'unset' => [null, 'not set'],
            'an array' => [['a', 'b', 'c'], '3 item(s)'],
            'an object' => [new \stdClass(), 'object'],
        ];
    }

    public function testSummarisesALongListRatherThanDumpingIt(): void
    {
        $recorder = new SettingsAuditRecorder();

        $recorder->record('adct_parish_intake_keywords', [], ['one', 'two']);

        self::assertSame('2 item(s)', $recorder->changes()[0]['after']);
    }

    public function testNeverRecordsTheValueOfASecret(): void
    {
        $recorder = new SettingsAuditRecorder(['adct_parish_intake_ai_api_key']);

        $recorder->record('adct_parish_intake_ai_api_key', '', 'sk-live-abc123');

        $recorded = $recorder->changes();
        self::assertSame([[
            'option' => 'adct_parish_intake_ai_api_key',
            'before' => 'not set',
            'after' => 'set',
        ]], $recorded);
        self::assertStringNotContainsString('sk-live-abc123', json_encode($recorded, JSON_THROW_ON_ERROR));
    }

    public function testStillRecordsASecretThatWasRotated(): void
    {
        $recorder = new SettingsAuditRecorder(['adct_parish_intake_ai_api_key']);

        $recorder->record('adct_parish_intake_ai_api_key', 'sk-live-old', 'sk-live-new');

        self::assertSame([[
            'option' => 'adct_parish_intake_ai_api_key',
            'before' => 'set',
            'after' => 'set',
        ]], $recorder->changes());
    }

    public function testRecordsASecretEvenWhenItDidNotChange(): void
    {
        $recorder = new SettingsAuditRecorder(['adct_parish_intake_ai_api_key']);

        $recorder->record('adct_parish_intake_ai_api_key', 'sk-live-same', 'sk-live-same');

        self::assertTrue($recorder->hasChanges());
    }

    public function testRemovalIsRecordedAsARemoval(): void
    {
        $recorder = new SettingsAuditRecorder();

        $recorder->recordRemoval('adct_parish_intake_ai_api_key', 'sk-live-abc123');

        self::assertSame([[
            'option' => 'adct_parish_intake_ai_api_key',
            'before' => 'sk-live-abc123',
            'after' => 'removed',
        ]], $recorder->changes());
    }

    public function testRemovingASecretDoesNotRecordItsValue(): void
    {
        $recorder = new SettingsAuditRecorder(['adct_parish_intake_ai_api_key']);

        $recorder->recordRemoval('adct_parish_intake_ai_api_key', 'sk-live-abc123');

        self::assertSame('set', $recorder->changes()[0]['before']);
    }

    public function testASecretThatWasNeverSetCountsAsNotSet(): void
    {
        $recorder = new SettingsAuditRecorder(['adct_parish_intake_ai_api_key']);

        $recorder->record('adct_parish_intake_ai_api_key', null, '   ');

        self::assertSame('not set', $recorder->changes()[0]['after']);
    }

    public function testIgnoresBlankSecretOptionNames(): void
    {
        $recorder = new SettingsAuditRecorder(['', 'adct_parish_intake_ai_api_key', 'adct_parish_intake_ai_api_key']);

        $recorder->record('adct_parish_intake_ai_api_key', '', 'sk-live-abc123');

        self::assertSame('set', $recorder->changes()[0]['after']);
    }

    public function testDetailsCarryTheWholeDiffUnderChanged(): void
    {
        $recorder = new SettingsAuditRecorder();

        $recorder->record('one', 'a', 'b');
        $recorder->recordRemoval('two', 'c');

        self::assertSame(['changed' => $recorder->changes()], $recorder->toDetails());
    }
}