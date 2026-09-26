<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Auth;

use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Auth\ActionTokenHandlerRegistry;
use ADCT\ParishIntake\Core\Auth\ActionTokenOutcome;
use ADCT\ParishIntake\Core\Auth\ActionTokenPreview;
use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
use ADCT\ParishIntake\Core\Ports\ActionTokenActionHandlerInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ActionTokenHandlerRegistryTest extends TestCase
{
    public function testHandlersAreResolvedByPurposeWithoutProvidingBusinessActions(): void
    {
        $handler = new RegistryTestActionTokenHandler(ActionTokenPurpose::CONFIRM);
        $registry = new ActionTokenHandlerRegistry([$handler]);

        self::assertSame($handler, $registry->forPurpose(ActionTokenPurpose::CONFIRM));
        self::assertNull($registry->forPurpose(ActionTokenPurpose::APPROVE_EVENT));
    }

    public function testOnlyOneHandlerCanBeRegisteredForEachPurpose(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only one action token handler');

        new ActionTokenHandlerRegistry([
            new RegistryTestActionTokenHandler(ActionTokenPurpose::CONFIRM),
            new RegistryTestActionTokenHandler(ActionTokenPurpose::CONFIRM),
        ]);
    }
}

final class RegistryTestActionTokenHandler implements ActionTokenActionHandlerInterface
{
    public function __construct(private ActionTokenPurpose $actionPurpose)
    {
    }

    public function purpose(): ActionTokenPurpose
    {
        return $this->actionPurpose;
    }

    public function preview(ActionTokenBinding $binding): ?ActionTokenPreview
    {
        return new ActionTokenPreview('Preview', 'Summary', 'Continue');
    }

    public function perform(ActionTokenBinding $binding): ActionTokenOutcome
    {
        return new ActionTokenOutcome('Action completed.');
    }
}
