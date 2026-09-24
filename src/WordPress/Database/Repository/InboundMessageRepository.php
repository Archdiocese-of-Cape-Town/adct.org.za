<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database\Repository;

final class InboundMessageRepository extends AbstractRepository
{
    protected const TABLE_SUFFIX = 'adct_pi_inbound_messages';

    protected const FIELD_FORMATS = [
        'source_id' => '%d',
        'external_id' => '%s',
        'content_hash' => '%s',
        'sender_email' => '%s',
        'sender_name' => '%s',
        'subject' => '%s',
        'received_at' => '%s',
        'raw_path' => '%s',
        'body_text' => '%s',
        'auth_results' => '%s',
        'is_auto_reply' => '%d',
        'status' => '%s',
        'error' => '%s',
        'retention_until' => '%s',
        'created_at' => '%s',
        'updated_at' => '%s',
    ];
}
