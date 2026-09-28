<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Auth;

use ADCT\ParishIntake\Core\Ports\ActionTokenActionHandlerInterface;

final class DenyActionHandler implements ActionTokenActionHandlerInterface
{
    /**
     * @param (callable(ActionTokenBinding): void)|null $onDeny
     */
    public function __construct(
        private readonly mixed $onDeny = null
    ) {
    }

    public function purpose(): ActionTokenPurpose
    {
        return ActionTokenPurpose::DENY;
    }

    public function preview(ActionTokenBinding $binding): ?ActionTokenPreview
    {
        return new ActionTokenPreview(
            'Deny Event Submission',
            'Please confirm that you want to reject and cancel this event submission.',
            'Deny Event Notice',
            [
                'Notice ID: ' . $binding->subjectId,
                'Email: ' . $binding->email,
            ]
        );
    }

    public function perform(ActionTokenBinding $binding): ActionTokenOutcome
    {
        if (is_callable($this->onDeny)) {
            ($this->onDeny)($binding);
        }

        return new ActionTokenOutcome('The event submission has been rejected and cancelled.');
    }
}
