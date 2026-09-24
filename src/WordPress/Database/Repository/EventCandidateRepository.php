<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database\Repository;

final class EventCandidateRepository extends AbstractRepository
{
    protected const TABLE_SUFFIX = 'adct_pi_event_candidates';

    protected const FIELD_FORMATS = [
        'message_id' => '%d',
        'block_index' => '%d',
        'parish_id' => '%d',
        'fields' => '%s',
        'recurrence' => '%s',
        'confidence' => '%f',
        'parser_version' => '%s',
        'strategies' => '%s',
        'notes' => '%s',
        'ai_used' => '%d',
        'ai_provider' => '%s',
        'ai_model' => '%s',
        'match_event_id' => '%d',
        'match_kind' => '%s',
        'status' => '%s',
        'confirmed_by' => '%s',
        'confirmed_at' => '%s',
        'approved_by' => '%s',
        'approved_at' => '%s',
        'approved_via' => '%s',
        'decided_by' => '%s',
        'decided_at' => '%s',
        'decision_note' => '%s',
        'created_at' => '%s',
        'updated_at' => '%s',
    ];
}
