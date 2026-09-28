<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Auth;

use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Auth\ActionTokenHandlerRegistry;
use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
use ADCT\ParishIntake\Core\Auth\ConfirmActionHandler;
use ADCT\ParishIntake\Core\Auth\DenyActionHandler;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ConfirmAndDenyActionHandlerTest extends TestCase
{
    public function testConfirmActionHandlerProvidesPreviewAndPerformsCallback(): void
    {
        $called = false;
        $binding = new ActionTokenBinding(
            ActionTokenPurpose::CONFIRM,
            'message',
            42,
            'secretary@example.test'
        );

        $handler = new ConfirmActionHandler(function (ActionTokenBinding $b) use (&$called, $binding): void {
            $called = true;
            $this->assertTrue($b->equals($binding));
        });

        $this->assertSame(ActionTokenPurpose::CONFIRM, $handler->purpose());

        $preview = $handler->preview($binding);
        $this->assertNotNull($preview);
        $this->assertSame('Confirm Event Submission', $preview->title);
        $this->assertSame('Confirm Event Notice', $preview->submitLabel);

        $outcome = $handler->perform($binding);
        $this->assertTrue($called);
        $this->assertStringContainsString('confirmed', $outcome->message);
    }

    public function testConfirmActionHandlerThrowsWithoutCallback(): void
    {
        $handler = new ConfirmActionHandler();
        $binding = new ActionTokenBinding(
            ActionTokenPurpose::CONFIRM,
            'message',
            42,
            'secretary@example.test'
        );

        $this->expectException(LogicException::class);
        $handler->perform($binding);
    }

    public function testDenyActionHandlerProvidesPreviewAndPerformsCallback(): void
    {
        $called = false;
        $binding = new ActionTokenBinding(
            ActionTokenPurpose::DENY,
            'message',
            42,
            'secretary@example.test'
        );

        $handler = new DenyActionHandler(function (ActionTokenBinding $b) use (&$called, $binding): void {
            $called = true;
            $this->assertTrue($b->equals($binding));
        });

        $this->assertSame(ActionTokenPurpose::DENY, $handler->purpose());

        $preview = $handler->preview($binding);
        $this->assertNotNull($preview);
        $this->assertSame('Deny Event Submission', $preview->title);
        $this->assertSame('Deny Event Notice', $preview->submitLabel);

        $outcome = $handler->perform($binding);
        $this->assertTrue($called);
        $this->assertStringContainsString('cancelled', $outcome->message);
    }

    public function testDenyActionHandlerThrowsWithoutCallback(): void
    {
        $handler = new DenyActionHandler();
        $binding = new ActionTokenBinding(
            ActionTokenPurpose::DENY,
            'message',
            42,
            'secretary@example.test'
        );

        $this->expectException(LogicException::class);
        $handler->perform($binding);
    }

    public function testHandlersCanBeRegisteredInRegistry(): void
    {
        $confirmHandler = new ConfirmActionHandler(static fn () => null);
        $denyHandler = new DenyActionHandler(static fn () => null);

        $registry = new ActionTokenHandlerRegistry([$confirmHandler, $denyHandler]);

        $this->assertSame($confirmHandler, $registry->forPurpose(ActionTokenPurpose::CONFIRM));
        $this->assertSame($denyHandler, $registry->forPurpose(ActionTokenPurpose::DENY));
    }
}
