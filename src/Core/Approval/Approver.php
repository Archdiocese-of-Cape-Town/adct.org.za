<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Approval;

use ADCT\ParishIntake\Core\Directory\EmailAddress;
use InvalidArgumentException;

final readonly class Approver
{
    public const NOTIFY_EACH = 'each';
    public const NOTIFY_DIGEST = 'digest';

    public int $id;
    public int $wpUserId;
    public string $email;
    public string $label;
    public string $notifyMode;
    public bool $remindersEnabled;
    public bool $active;

    public function __construct(
        int $id,
        int $wpUserId,
        string $email,
        string $label,
        string $notifyMode,
        bool $remindersEnabled,
        bool $active
    ) {
        if ($id < 1 || $wpUserId < 1) {
            throw new InvalidArgumentException('Approver IDs must be positive.');
        }

        if (! self::isValidNotifyMode($notifyMode)) {
            throw new InvalidArgumentException('The notification mode must be each or digest.');
        }

        $this->id = $id;
        $this->wpUserId = $wpUserId;
        $this->email = EmailAddress::normalize($email);
        $this->label = trim($label);
        $this->notifyMode = $notifyMode;
        $this->remindersEnabled = $remindersEnabled;
        $this->active = $active;
    }

    public static function isValidNotifyMode(string $notifyMode): bool
    {
        return in_array($notifyMode, [self::NOTIFY_EACH, self::NOTIFY_DIGEST], true);
    }
}
