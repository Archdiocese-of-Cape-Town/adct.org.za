<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database\Repository;

final class AttachmentRepository extends AbstractRepository
{
    protected const TABLE_SUFFIX = 'adct_pi_attachments';

    protected const FIELD_FORMATS = [
        'message_id' => '%d',
        'filename' => '%s',
        'mime_type' => '%s',
        'size_bytes' => '%d',
        'storage_path' => '%s',
        'content_hash' => '%s',
        'extracted_text' => '%s',
        'extraction_method' => '%s',
        'status' => '%s',
        'created_at' => '%s',
        'updated_at' => '%s',
    ];
}
