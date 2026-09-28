<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Auth;

use ADCT\ParishIntake\Core\Ports\ActionTokenActionHandlerInterface;
use LogicException;

final class ConfirmActionHandler implements ActionTokenActionHandlerInterface
{
    /**
     * @param (callable(ActionTokenBinding): void)|null $onConfirm
     */
    public function __construct(
        private readonly mixed $onConfirm = null
    ) {
    }

    public function purpose(): ActionTokenPurpose
    {
        return ActionTokenPurpose::CONFIRM;
    }

    public function preview(ActionTokenBinding $binding): ?ActionTokenPreview
    {
        return new ActionTokenPreview(
            'Confirm Event Submission',
            'Please confirm that you submitted this parish event notice and wish to send it for approval.',
            'Confirm Event Notice',
            [
                'Notice ID: ' . $binding->subjectId,
                'Email: ' . $binding->email,
            ]
        );
    }

    public function perform(ActionTokenBinding $binding): ActionTokenOutcome
    {
        if (! is_callable($this->onConfirm)) {
            throw new LogicException('No confirmation handler callback was configured.');
        }

        ($this->onConfirm)($binding);

        return new ActionTokenOutcome('Your event submission has been confirmed and submitted for approval.');
    }
}
