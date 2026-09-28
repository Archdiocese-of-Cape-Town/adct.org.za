<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Auth;

use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Auth\ActionTokenHandlerRegistry;
use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
use ADCT\ParishIntake\Core\Auth\ApproveEventActionHandler;
use ADCT\ParishIntake\Core\Auth\RejectEventActionHandler;
use ADCT\ParishIntake\Core\Auth\RevertChangeActionHandler;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ApprovalActionHandlersTest extends TestCase
{
    public function testApproveEventActionHandlerProvidesPreviewAndPerformsCallback(): void
    {
        $called = false;
        $binding = new ActionTokenBinding(
            ActionTokenPurpose::APPROVE_EVENT,
            'candidate',
            101,
            'dean@example.test'
        );

        $handler = new ApproveEventActionHandler(function (ActionTokenBinding $b) use (&$called, $binding): void {
            $called = true;
            $this->assertTrue($b->equals($binding));
        });

        $this->assertSame(ActionTokenPurpose::APPROVE_EVENT, $handler->purpose());

        $preview = $handler->preview($binding);
        $this->assertNotNull($preview);
        $this->assertSame('Approve Parish Event', $preview->title);
        $this->assertSame('Approve Event', $preview->submitLabel);

        $outcome = $handler->perform($binding);
        $this->assertTrue($called);
        $this->assertStringContainsString('approved', $outcome->message);
    }

    public function testApproveEventActionHandlerThrowsWithoutCallback(): void
    {
        $handler = new ApproveEventActionHandler();
        $binding = new ActionTokenBinding(
            ActionTokenPurpose::APPROVE_EVENT,
            'candidate',
            101,
            'dean@example.test'
        );

        $this->expectException(LogicException::class);
        $handler->perform($binding);
    }

    public function testRejectEventActionHandlerProvidesPreviewAndPerformsCallback(): void
    {
        $called = false;
        $binding = new ActionTokenBinding(
            ActionTokenPurpose::REJECT_EVENT,
            'candidate',
            102,
            'dean@example.test'
        );

        $handler = new RejectEventActionHandler(function (ActionTokenBinding $b) use (&$called, $binding): void {
            $called = true;
            $this->assertTrue($b->equals($binding));
        });

        $this->assertSame(ActionTokenPurpose::REJECT_EVENT, $handler->purpose());

        $preview = $handler->preview($binding);
        $this->assertNotNull($preview);
        $this->assertSame('Reject Parish Event', $preview->title);
        $this->assertSame('Reject Event', $preview->submitLabel);

        $outcome = $handler->perform($binding);
        $this->assertTrue($called);
        $this->assertStringContainsString('rejected', $outcome->message);
    }

    public function testRejectEventActionHandlerThrowsWithoutCallback(): void
    {
        $handler = new RejectEventActionHandler();
        $binding = new ActionTokenBinding(
            ActionTokenPurpose::REJECT_EVENT,
            'candidate',
            102,
            'dean@example.test'
        );

        $this->expectException(LogicException::class);
        $handler->perform($binding);
    }

    public function testRevertChangeActionHandlerProvidesPreviewAndPerformsCallback(): void
    {
        $called = false;
        $binding = new ActionTokenBinding(
            ActionTokenPurpose::REVERT_CHANGE,
            'event',
            201,
            'reviewer@example.test'
        );

        $handler = new RevertChangeActionHandler(function (ActionTokenBinding $b) use (&$called, $binding): void {
            $called = true;
            $this->assertTrue($b->equals($binding));
        });

        $this->assertSame(ActionTokenPurpose::REVERT_CHANGE, $handler->purpose());

        $preview = $handler->preview($binding);
        $this->assertNotNull($preview);
        $this->assertSame('Revert Event Changes', $preview->title);
        $this->assertSame('Revert Event Changes', $preview->submitLabel);

        $outcome = $handler->perform($binding);
        $this->assertTrue($called);
        $this->assertStringContainsString('reverted', $outcome->message);
    }

    public function testRevertChangeActionHandlerThrowsWithoutCallback(): void
    {
        $handler = new RevertChangeActionHandler();
        $binding = new ActionTokenBinding(
            ActionTokenPurpose::REVERT_CHANGE,
            'event',
            201,
            'reviewer@example.test'
        );

        $this->expectException(LogicException::class);
        $handler->perform($binding);
    }

    public function testApprovalHandlersCanBeRegisteredInRegistry(): void
    {
        $approve = new ApproveEventActionHandler(static fn () => null);
        $reject = new RejectEventActionHandler(static fn () => null);
        $revert = new RevertChangeActionHandler(static fn () => null);

        $registry = new ActionTokenHandlerRegistry([$approve, $reject, $revert]);

        $this->assertSame($approve, $registry->forPurpose(ActionTokenPurpose::APPROVE_EVENT));
        $this->assertSame($reject, $registry->forPurpose(ActionTokenPurpose::REJECT_EVENT));
        $this->assertSame($revert, $registry->forPurpose(ActionTokenPurpose::REVERT_CHANGE));
    }
}
