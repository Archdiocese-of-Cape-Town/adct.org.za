<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Parsing;

use ADCT\ParishIntake\Core\Parsing\ParseOutcome;
use ADCT\ParishIntake\Core\Parsing\ParseResult;
use ADCT\ParishIntake\Tests\Support\EmailFixtureResult;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EmailFixtureResultTest extends TestCase
{
    public function testMatchExpectationsRequireExistingEventContext(): void
    {
        $result = new ParseResult();
        $outcome = new ParseOutcome([], [], [], [], $result);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('require existing_event context');

        EmailFixtureResult::actual(['match_kind' => 'cancellation'], $outcome);
    }
}
