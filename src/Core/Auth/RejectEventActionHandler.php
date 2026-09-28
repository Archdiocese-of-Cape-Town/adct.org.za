<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Auth;

use ADCT\ParishIntake\Core\Ports\ActionTokenActionHandlerInterface;
use LogicException;

final class RejectEventActionHandler implements ActionTokenActionHandlerInterface
{
    /**
     * @param (callable(ActionTokenBinding): void)|null $onReject
     */
    public function __construct(
        private readonly mixed $onReject = null
    ) {
    }

    public function purpose(): ActionTokenPurpose
    {
        return ActionTokenPurpose::REJECT_EVENT;
    }

    public function preview(ActionTokenBinding $binding): ?ActionTokenPreview
    {
        return new ActionTokenPreview(
            'Reject Parish Event',
            'Please confirm that you want to reject this parish event submission.',
            'Reject Event',
            [
                'Candidate ID: ' . $binding->subjectId,
                'Approver: ' . $binding->email,
            ]
        );
    }

    public function perform(ActionTokenBinding $binding): ActionTokenOutcome
    {
        if (! is_callable($this->onReject)) {
            throw new LogicException('No reject event handler callback was configured.');
        }

        ($this->onReject)($binding);

        return new ActionTokenOutcome('The parish event submission has been rejected.');
    }
}
