<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Database;

use ADCT\ParishIntake\WordPress\Database\ReviewQueueJson;
use ADCT\ParishIntake\WordPress\Database\WordPressDatabaseConnection;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReviewQueueJsonTest extends TestCase
{
    public function testProductionExpressionsAreUnchanged(): void
    {
        $connection = new WordPressDatabaseConnection(new \stdClass());
        self::assertFalse($connection->isSqlite());
        self::assertSame('JSON_CONTAINS(c.notes, \'"unknown_sender"\')', $connection->reviewQueueNoteExpression());
        self::assertSame("JSON_UNQUOTE(JSON_EXTRACT(c.fields, '$.title'))", $connection->reviewQueueTextExpression('$.title'));
        self::assertSame("JSON_UNQUOTE(JSON_EXTRACT(c.fields, '$.match_review_required'))",
            $connection->reviewQueueTextExpression('$.match_review_required'));
    }

    public function testInvalidJsonCannotBecomeASuccessShapedFallback(): void
    {
        $this->expectException(JsonException::class);
        ReviewQueueJson::containsUnknownSender('invalid JSON');
    }

    public function testInvalidFieldJsonCannotBecomeEmptyText(): void
    {
        $this->expectException(JsonException::class);
        ReviewQueueJson::text('invalid JSON', '$.title');
    }

    #[DataProvider('notes')]
    public function testScalarContainmentMatchesMySql(string $json, int $expected): void
    {
        self::assertSame($expected, ReviewQueueJson::containsUnknownSender($json));
    }

    public static function notes(): array
    {
        return [
            ['[]', 0], ['["unknown_sender"]', 1], ['[["unknown_sender"]]', 1],
            ['"unknown_sender"', 1], ['["unknown_sender_extra"]', 0],
            ['{"note":"unknown_sender"}', 0], ['[{"note":"unknown_sender"}]', 0],
            ['["\\u0075nknown_sender"]', 1], ['null', 0], ['false', 0],
            ['["unknown_sender",false,42]', 1],
        ];
    }

    #[DataProvider('fields')]
    public function testUnquotedExtractionPreservesJsonTypes(string $json, ?string $expected): void
    {
        self::assertSame($expected, ReviewQueueJson::text($json, '$.value'));
    }

    public static function fields(): array
    {
        return [
            ['{"value":true}', 'true'], ['{"value":"true"}', 'true'],
            ['{"value":1}', '1'], ['{"value":false}', 'false'],
            ['{"value":"A \\"quoted\\" title"}', 'A "quoted" title'],
            ['{"value":null}', 'null'], ['{}', null],
        ];
    }
}
