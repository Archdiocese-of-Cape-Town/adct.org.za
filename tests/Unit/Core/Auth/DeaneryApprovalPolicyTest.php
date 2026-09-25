<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Auth;

use ADCT\ParishIntake\Core\Auth\Capabilities;
use ADCT\ParishIntake\Core\Auth\DeaneryApprovalPolicy;
use PHPUnit\Framework\TestCase;

final class DeaneryApprovalPolicyTest extends TestCase
{
    private DeaneryApprovalPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new DeaneryApprovalPolicy();
    }

    public function testDeanCanApproveParishesInAnAssignedDeanery(): void
    {
        self::assertTrue($this->policy->canApprove(
            [12],
            12,
            [Capabilities::APPROVE_DEANERY]
        ));
    }

    public function testDeanCannotApproveParishesInAnotherDeanery(): void
    {
        self::assertFalse($this->policy->canApprove(
            [12],
            13,
            [Capabilities::APPROVE_DEANERY]
        ));
    }

    public function testDeanCannotApproveParishWithoutADeanery(): void
    {
        self::assertFalse($this->policy->canApprove(
            [12],
            null,
            [Capabilities::APPROVE_DEANERY]
        ));
    }

    public function testArchdioceseReviewerCanApproveAnyDeaneryIncludingNull(): void
    {
        self::assertTrue($this->policy->canApprove(
            [],
            13,
            [Capabilities::REVIEW]
        ));
        self::assertTrue($this->policy->canApprove(
            [],
            null,
            [Capabilities::REVIEW]
        ));
    }

    public function testUserWithoutAnApprovalCapabilityCannotApprove(): void
    {
        self::assertFalse($this->policy->canApprove([12], 12, ['read']));
    }
}
