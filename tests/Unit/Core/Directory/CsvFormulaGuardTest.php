<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Directory;

use ADCT\ParishIntake\Core\Directory\CsvFormulaGuard;
use PHPUnit\Framework\TestCase;

final class CsvFormulaGuardTest extends TestCase
{
    public function testFormulaPrefixesAreEscapedAndCanBeRemoved(): void
    {
        foreach (['=1+1', '+value', '-value', '@value', "\tvalue", "\rvalue"] as $value) {
            $escaped = CsvFormulaGuard::protect($value);

            self::assertSame("'" . $value, $escaped);
            self::assertSame($value, CsvFormulaGuard::unprotect($escaped));
        }
    }

    public function testOnlyARecognisedPhoneNumberIsExemptFromPlusPrefixEscaping(): void
    {
        self::assertSame(
            '+27 00 000 0000',
            CsvFormulaGuard::protect('+27 00 000 0000', true)
        );
        self::assertSame(
            "'+27 00 0",
            CsvFormulaGuard::protect('+27 00 0', true)
        );
        self::assertSame(
            "'+27 00 000 0000",
            CsvFormulaGuard::protect('+27 00 000 0000', false)
        );
    }

    public function testExistingLeadingQuotesRoundTripWithoutBeingMistakenForAnEscape(): void
    {
        foreach (["'=1+1", "''=1+1", "'ordinary text"] as $value) {
            self::assertSame($value, CsvFormulaGuard::unprotect(CsvFormulaGuard::protect($value)));
        }
    }
}
