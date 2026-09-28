<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Auth;

use ADCT\ParishIntake\Core\Ports\ActionTokenActionHandlerInterface;
use LogicException;

final class ApproveEventActionHandler implements ActionTokenActionHandlerInterface
{
    /**
     * @param (callable(ActionTokenBinding): void)|null $onApprove
     */
    public function __construct(
        private readonly mixed $onApprove = null
    ) {
    }

    public function purpose(): ActionTokenPurpose
    {
        return ActionTokenPurpose::APPROVE_EVENT;
    }

    public function preview(ActionTokenBinding $binding): ?ActionTokenPreview
    {
        return new ActionTokenPreview(
            'Approve Parish Event',
            'Please confirm that you want to approve this parish event for publication on the archdiocese website.',
            'Approve Event',
            [
                'Candidate ID: ' . $binding->subjectId,
                'Approver: ' . $binding->email,
            ]
        );
    }

    public function perform(ActionTokenBinding $binding): ActionTokenOutcome
    {
        if (! is_callable($this->onApprove)) {
            throw new LogicException('No approve event handler callback was configured.');
        }

        ($this->onApprove)($binding);

        return new ActionTokenOutcome('The parish event has been approved and published.');
    }
}
