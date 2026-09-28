<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Auth;

use ADCT\ParishIntake\Core\Ports\ActionTokenActionHandlerInterface;
use LogicException;

final class RevertChangeActionHandler implements ActionTokenActionHandlerInterface
{
    /**
     * @param (callable(ActionTokenBinding): void)|null $onRevert
     */
    public function __construct(
        private readonly mixed $onRevert = null
    ) {
    }

    public function purpose(): ActionTokenPurpose
    {
        return ActionTokenPurpose::REVERT_CHANGE;
    }

    public function preview(ActionTokenBinding $binding): ?ActionTokenPreview
    {
        return new ActionTokenPreview(
            'Revert Event Changes',
            'Please confirm that you want to revert recent changes to this parish event and restore its previous version.',
            'Revert Event Changes',
            [
                'Event ID: ' . $binding->subjectId,
                'Reviewer: ' . $binding->email,
            ]
        );
    }

    public function perform(ActionTokenBinding $binding): ActionTokenOutcome
    {
        if (! is_callable($this->onRevert)) {
            throw new LogicException('No revert change handler callback was configured.');
        }

        ($this->onRevert)($binding);

        return new ActionTokenOutcome('The event changes have been reverted to the previous version.');
    }
}
