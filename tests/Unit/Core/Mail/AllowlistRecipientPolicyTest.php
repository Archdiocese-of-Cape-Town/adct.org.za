<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Mail;

use ADCT\ParishIntake\Core\Mail\AllowlistRecipientPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AllowlistRecipientPolicyTest extends TestCase
{
    public function testAllowsAnExactEmailAddressCaseInsensitively(): void
    {
        $policy = new AllowlistRecipientPolicy(true, ['Approved@Example.test']);

        self::assertTrue($policy->allows('approved@example.test'));
        self::assertFalse($policy->allows('other@example.test'));
    }

    public function testAllowsEveryAddressAtAnExplicitExactDomain(): void
    {
        $policy = new AllowlistRecipientPolicy(true, ['@example.test']);

        self::assertTrue($policy->allows('parish.office@example.test'));
        self::assertFalse($policy->allows('parish.office@sub.example.test'));
        self::assertFalse($policy->allows('parish.office@notexample.test'));
    }

    public function testAnEnabledEmptyAllowlistFailsClosed(): void
    {
        $policy = new AllowlistRecipientPolicy(true, []);

        self::assertFalse($policy->allows('parish@example.test'));
    }

    public function testRejectsAnInvalidDomainEntryInsteadOfPartiallyApplyingTheList(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AllowlistRecipientPolicy(true, ['approved@example.test', '@example..test']);
    }
}
