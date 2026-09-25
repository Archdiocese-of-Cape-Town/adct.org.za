<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Approval;

use ADCT\ParishIntake\Core\Directory\EmailAddress;
use InvalidArgumentException;

final readonly class ApproverSettings
{
    public const NOTIFY_EACH = Approver::NOTIFY_EACH;
    public const NOTIFY_DIGEST = Approver::NOTIFY_DIGEST;

    public string $email;
    public string $label;
    public string $notifyMode;
    public bool $remindersEnabled;
    public bool $active;

    public function __construct(
        string $email,
        string $label,
        string $notifyMode,
        bool $remindersEnabled,
        bool $active
    ) {
        $label = trim($label);

        if ($label === '') {
            throw new InvalidArgumentException('An approver label is required.');
        }

        if (strlen($label) > 191) {
            throw new InvalidArgumentException('The approver label must be no longer than 191 characters.');
        }

        if (! Approver::isValidNotifyMode($notifyMode)) {
            throw new InvalidArgumentException('The notification mode must be each or digest.');
        }

        $this->email = EmailAddress::normalize($email);
        $this->label = $label;
        $this->notifyMode = $notifyMode;
        $this->remindersEnabled = $remindersEnabled;
        $this->active = $active;
    }
}
