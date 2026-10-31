<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Approval;

use ADCT\ParishIntake\Core\Approval\ApprovalPreviewText as CoreApprovalPreviewText;

final class ApprovalPreviewText
{
    public static function date(string $value): string
    {
        return CoreApprovalPreviewText::date($value);
    }

    public static function editDate(string $value): string
    {
        return CoreApprovalPreviewText::editDate($value);
    }

    public static function shorten(string $value): string
    {
        return CoreApprovalPreviewText::shorten($value);
    }
}
