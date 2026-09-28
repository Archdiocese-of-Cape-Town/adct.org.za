<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Auth;

use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Auth\ActionTokenHandlerRegistry;
use ADCT\ParishIntake\Core\Auth\ActionTokenOutcome;
use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
use ADCT\ParishIntake\Core\Auth\ConfirmActionHandler;
use ADCT\ParishIntake\Core\Auth\DenyActionHandler;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ConfirmAndDenyActionHandlerTest extends TestCase
{
    /**
     * @dataProvider handlerProvider
     */
    public function testHandlersProvidePreviewDetailsAndDefaultOutcome(
        object $handler,
        ActionTokenBinding $binding,
        string $expectedTitle,
        string $expectedSummary,
        string $expectedLabel,
        string $expectedMessage
    ): void {
        $preview = $handler->preview($binding);
        $outcome = $handler->perform($binding);

        self::assertNotNull($preview);
        self::assertSame($expectedTitle, $preview->title);
        self::assertSame($expectedSummary, $preview->summary);
        self::assertSame($expectedLabel, $preview->submitLabel);
        self::assertSame([], $preview->details);
        self::assertSame($expectedMessage, $outcome->message);
    }

    public function testHandlersInvokeCustomExecutionCallbacks(): void
    {
        $confirmBinding = $this->binding(ActionTokenPurpose::CONFIRM);
        $denyBinding = $this->binding(ActionTokenPurpose::DENY);
        $receivedBindings = [];
        $confirm = new ConfirmActionHandler(
            callback: static function (ActionTokenBinding $binding) use (&$receivedBindings): ActionTokenOutcome {
                $receivedBindings[] = $binding;

                return new ActionTokenOutcome('Confirmed via callback.');
            }
        );
        $deny = new DenyActionHandler(
            callback: static function (ActionTokenBinding $binding) use (&$receivedBindings): ActionTokenOutcome {
                $receivedBindings[] = $binding;

                return new ActionTokenOutcome('Denied via callback.');
            }
        );

        self::assertSame('Confirmed via callback.', $confirm->perform($confirmBinding)->message);
        self::assertSame('Denied via callback.', $deny->perform($denyBinding)->message);
        self::assertSame([$confirmBinding, $denyBinding], $receivedBindings);
    }

    /**
     * @dataProvider mismatchedHandlerProvider
     */
    public function testHandlersRejectMismatchedBindings(
        object $handler,
        ActionTokenBinding $binding,
        string $expectedMessage
    ): void {
        self::assertNull($handler->preview($binding));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        $handler->perform($binding);
    }

    public function testHandlersCanBeResolvedFromTheRegistry(): void
    {
        $confirm = new ConfirmActionHandler();
        $deny = new DenyActionHandler();
        $registry = new ActionTokenHandlerRegistry([$confirm, $deny]);

        self::assertSame($confirm, $registry->forPurpose(ActionTokenPurpose::CONFIRM));
        self::assertSame($deny, $registry->forPurpose(ActionTokenPurpose::DENY));
    }

    /**
     * @return iterable<string, array{0: object, 1: ActionTokenBinding, 2: string, 3: string, 4: string, 5: string}>
     */
    public static function handlerProvider(): iterable
    {
        yield 'confirm' => [
            new ConfirmActionHandler(),
            self::staticBinding(ActionTokenPurpose::CONFIRM),
            'Confirm submission',
            'Please confirm these event details.',
            'Confirm',
            'Thank you. Your confirmation has been recorded.',
        ];

        yield 'deny' => [
            new DenyActionHandler(),
            self::staticBinding(ActionTokenPurpose::DENY),
            'Deny submission',
            'Please confirm that these event details should be denied.',
            'Deny',
            'Thank you. Your denial has been recorded.',
        ];
    }

    /**
     * @return iterable<string, array{0: object, 1: ActionTokenBinding, 2: string}>
     */
    public static function mismatchedHandlerProvider(): iterable
    {
        yield 'confirm handler with deny binding' => [
            new ConfirmActionHandler(),
            self::staticBinding(ActionTokenPurpose::DENY),
            'The action token binding does not match the confirm handler.',
        ];

        yield 'deny handler with confirm binding' => [
            new DenyActionHandler(),
            self::staticBinding(ActionTokenPurpose::CONFIRM),
            'The action token binding does not match the deny handler.',
        ];
    }

    private function binding(ActionTokenPurpose $purpose): ActionTokenBinding
    {
        return self::staticBinding($purpose);
    }

    private static function staticBinding(ActionTokenPurpose $purpose): ActionTokenBinding
    {
        return new ActionTokenBinding($purpose, 'event_candidate', 42, 'submitter@example.test');
    }
}
