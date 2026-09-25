<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Approval;

use InvalidArgumentException;

final readonly class ApprovalRoute
{
    public const REASON_NO_DEANERY = 'no_deanery';
    public const REASON_NO_ACTIVE_APPROVER = 'no_active_approver';
    public const REASON_DEANERY_INACTIVE = 'deanery_inactive';
    public const REASON_OK = 'ok';

    /**
     * @var list<Approver>
     */
    public array $approvers;

    public bool $reviewersOnly;
    public string $reason;

    /**
     * @param list<Approver> $approvers
     */
    public function __construct(array $approvers, bool $reviewersOnly, string $reason)
    {
        if (! in_array($reason, [
            self::REASON_NO_DEANERY,
            self::REASON_NO_ACTIVE_APPROVER,
            self::REASON_DEANERY_INACTIVE,
            self::REASON_OK,
        ], true)) {
            throw new InvalidArgumentException('The approval route reason is not valid.');
        }

        if (($reason === self::REASON_OK) === $reviewersOnly || ($reviewersOnly && $approvers !== [])) {
            throw new InvalidArgumentException('The approval route result is inconsistent.');
        }

        foreach ($approvers as $approver) {
            if (! $approver instanceof Approver || ! $approver->active) {
                throw new InvalidArgumentException('An approval route can include only active approvers.');
            }
        }

        if (! $reviewersOnly && $approvers === []) {
            throw new InvalidArgumentException('A deanery route requires at least one active approver.');
        }

        $this->approvers = array_values($approvers);
        $this->reviewersOnly = $reviewersOnly;
        $this->reason = $reason;
    }
}
