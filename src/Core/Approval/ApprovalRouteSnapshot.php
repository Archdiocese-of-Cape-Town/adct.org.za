<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Approval;

use InvalidArgumentException;

final readonly class ApprovalRouteSnapshot
{
    public ?int $deaneryId;
    public bool $deaneryActive;

    /**
     * @var list<Approver>
     */
    public array $approvers;

    /**
     * @param list<Approver> $approvers All assignments, including inactive ones.
     */
    public function __construct(?int $deaneryId, bool $deaneryActive, array $approvers)
    {
        if ($deaneryId !== null && $deaneryId < 1) {
            throw new InvalidArgumentException('A deanery ID must be positive when set.');
        }

        foreach ($approvers as $approver) {
            if (! $approver instanceof Approver) {
                throw new InvalidArgumentException('An approval route snapshot requires approver records.');
            }
        }

        $this->deaneryId = $deaneryId;
        $this->deaneryActive = $deaneryActive;
        $this->approvers = array_values($approvers);
    }
}
