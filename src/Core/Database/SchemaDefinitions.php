<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Database;

final class SchemaDefinitions
{
    public const VERSION = 1;

    /**
     * @return array<string, string> Map of table suffixes to dbDelta SQL templates.
     */
    public static function statements(): array
    {
        return [
            'adct_pi_deaneries' => <<<'SQL'
CREATE TABLE {table_prefix}adct_pi_deaneries (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    name varchar(191) NOT NULL,
    slug varchar(191) NOT NULL,
    dean_name varchar(191) NULL,
    vice_dean_name varchar(191) NULL,
    secretary_name varchar(191) NULL,
    status varchar(20) NOT NULL DEFAULT 'active',
    created_at datetime NOT NULL,
    updated_at datetime NOT NULL,
    PRIMARY KEY  (id),
    UNIQUE KEY slug (slug),
    KEY status (status)
) {charset_collate};
SQL,
            'adct_pi_deanery_approvers' => <<<'SQL'
CREATE TABLE {table_prefix}adct_pi_deanery_approvers (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    deanery_id bigint(20) unsigned NOT NULL,
    wp_user_id bigint(20) unsigned NOT NULL,
    email varchar(191) NOT NULL,
    label varchar(191) NOT NULL DEFAULT '',
    notify_mode varchar(20) NOT NULL DEFAULT 'each',
    reminders_enabled tinyint(1) NOT NULL DEFAULT 1,
    active tinyint(1) NOT NULL DEFAULT 1,
    created_at datetime NOT NULL,
    updated_at datetime NOT NULL,
    PRIMARY KEY  (id),
    UNIQUE KEY deanery_user (deanery_id,wp_user_id),
    KEY deanery_active (deanery_id,active),
    KEY email (email)
) {charset_collate};
SQL,
            'adct_pi_parishes' => <<<'SQL'
CREATE TABLE {table_prefix}adct_pi_parishes (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    name varchar(191) NOT NULL,
    slug varchar(191) NOT NULL,
    area varchar(191) NULL,
    church varchar(191) NULL,
    kind varchar(30) NOT NULL DEFAULT 'parish',
    parent_parish_id bigint(20) unsigned NULL,
    deanery_id bigint(20) unsigned NULL,
    address text NULL,
    suburb varchar(191) NULL,
    latitude decimal(9,6) NULL,
    longitude decimal(9,6) NULL,
    website varchar(255) NULL,
    phone varchar(50) NULL,
    official_source_id bigint(20) unsigned NULL,
    expected_cadence_days smallint(5) unsigned NULL,
    reminders_enabled tinyint(1) NOT NULL DEFAULT 1,
    status varchar(20) NOT NULL DEFAULT 'active',
    notes longtext NULL,
    created_at datetime NOT NULL,
    updated_at datetime NOT NULL,
    PRIMARY KEY  (id),
    UNIQUE KEY slug (slug),
    KEY parent_parish_id (parent_parish_id),
    KEY deanery_id (deanery_id),
    KEY official_source_id (official_source_id),
    KEY status (status)
) {charset_collate};
SQL,
            'adct_pi_venues' => <<<'SQL'
CREATE TABLE {table_prefix}adct_pi_venues (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    parish_id bigint(20) unsigned NOT NULL,
    name varchar(191) NOT NULL,
    address text NULL,
    suburb varchar(191) NULL,
    latitude decimal(9,6) NULL,
    longitude decimal(9,6) NULL,
    created_at datetime NOT NULL,
    updated_at datetime NOT NULL,
    PRIMARY KEY  (id),
    KEY parish_id (parish_id),
    KEY parish_name (parish_id,name)
) {charset_collate};
SQL,
            'adct_pi_parish_contacts' => <<<'SQL'
CREATE TABLE {table_prefix}adct_pi_parish_contacts (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    parish_id bigint(20) unsigned NOT NULL,
    email varchar(191) NOT NULL,
    display_name varchar(191) NULL,
    role_label varchar(191) NULL,
    trust varchar(20) NOT NULL DEFAULT 'unknown',
    verified_at datetime NULL,
    wp_user_id bigint(20) unsigned NULL,
    last_seen_at datetime NULL,
    receives_reminders tinyint(1) NOT NULL DEFAULT 1,
    created_at datetime NOT NULL,
    updated_at datetime NOT NULL,
    PRIMARY KEY  (id),
    UNIQUE KEY parish_email (parish_id,email),
    KEY trust (trust),
    KEY wp_user_id (wp_user_id)
) {charset_collate};
SQL,
            'adct_pi_sources' => <<<'SQL'
CREATE TABLE {table_prefix}adct_pi_sources (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    parish_id bigint(20) unsigned NULL,
    type varchar(32) NOT NULL,
    identifier varchar(191) NOT NULL,
    role varchar(20) NOT NULL DEFAULT 'monitored',
    status varchar(20) NOT NULL DEFAULT 'active',
    poll_interval_minutes int(10) unsigned NULL,
    checkpoint longtext NULL,
    last_checked_at datetime NULL,
    last_success_at datetime NULL,
    last_item_at datetime NULL,
    consecutive_failures smallint(5) unsigned NOT NULL DEFAULT 0,
    last_error text NULL,
    created_at datetime NOT NULL,
    updated_at datetime NOT NULL,
    PRIMARY KEY  (id),
    UNIQUE KEY parish_type_identifier (parish_id,type,identifier),
    KEY status (status)
) {charset_collate};
SQL,
            'adct_pi_inbound_messages' => <<<'SQL'
CREATE TABLE {table_prefix}adct_pi_inbound_messages (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    source_id bigint(20) unsigned NOT NULL,
    external_id varchar(191) NULL,
    content_hash char(64) NULL,
    sender_email varchar(191) NULL,
    sender_name varchar(191) NULL,
    subject text NULL,
    received_at datetime NOT NULL,
    raw_path varchar(255) NULL,
    body_text longtext NULL,
    auth_results longtext NULL,
    is_auto_reply tinyint(1) NOT NULL DEFAULT 0,
    status varchar(20) NOT NULL DEFAULT 'received',
    error text NULL,
    retention_until datetime NULL,
    created_at datetime NOT NULL,
    updated_at datetime NOT NULL,
    PRIMARY KEY  (id),
    UNIQUE KEY source_external (source_id,external_id),
    UNIQUE KEY source_content (source_id,content_hash),
    KEY received_at (received_at),
    KEY status (status)
) {charset_collate};
SQL,
            'adct_pi_attachments' => <<<'SQL'
CREATE TABLE {table_prefix}adct_pi_attachments (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    message_id bigint(20) unsigned NOT NULL,
    filename varchar(191) NOT NULL,
    mime_type varchar(100) NOT NULL DEFAULT 'application/octet-stream',
    size_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
    storage_path varchar(255) NOT NULL,
    content_hash char(64) NULL,
    extracted_text longtext NULL,
    extraction_method varchar(32) NOT NULL DEFAULT 'none',
    status varchar(20) NOT NULL DEFAULT 'pending',
    created_at datetime NOT NULL,
    updated_at datetime NOT NULL,
    PRIMARY KEY  (id),
    KEY message_id (message_id),
    KEY content_hash (content_hash)
) {charset_collate};
SQL,
            'adct_pi_event_candidates' => <<<'SQL'
CREATE TABLE {table_prefix}adct_pi_event_candidates (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    message_id bigint(20) unsigned NULL,
    block_index int(10) unsigned NOT NULL DEFAULT 0,
    parish_id bigint(20) unsigned NULL,
    fields longtext NULL,
    recurrence longtext NULL,
    confidence decimal(4,3) NOT NULL DEFAULT 0.000,
    parser_version varchar(50) NOT NULL DEFAULT '',
    strategies longtext NULL,
    notes longtext NULL,
    ai_used tinyint(1) NOT NULL DEFAULT 0,
    ai_provider varchar(100) NULL,
    ai_model varchar(191) NULL,
    match_event_id bigint(20) unsigned NULL,
    match_kind varchar(20) NOT NULL DEFAULT 'new',
    status varchar(32) NOT NULL DEFAULT 'draft',
    confirmed_by varchar(191) NULL,
    confirmed_at datetime NULL,
    approved_by varchar(191) NULL,
    approved_at datetime NULL,
    approved_via varchar(32) NULL,
    decided_by varchar(191) NULL,
    decided_at datetime NULL,
    decision_note text NULL,
    created_at datetime NOT NULL,
    updated_at datetime NOT NULL,
    PRIMARY KEY  (id),
    UNIQUE KEY message_block (message_id,block_index),
    KEY parish_status (parish_id,status),
    KEY match_event_id (match_event_id),
    KEY status (status)
) {charset_collate};
SQL,
            'adct_pi_occurrences' => <<<'SQL'
CREATE TABLE {table_prefix}adct_pi_occurrences (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    event_id bigint(20) unsigned NOT NULL,
    start_utc datetime NOT NULL,
    end_utc datetime NULL,
    start_local_date date NOT NULL,
    parish_id bigint(20) unsigned NOT NULL,
    event_type_term_id bigint(20) unsigned NULL,
    latitude decimal(9,6) NULL,
    longitude decimal(9,6) NULL,
    is_cancelled tinyint(1) NOT NULL DEFAULT 0,
    created_at datetime NOT NULL,
    updated_at datetime NOT NULL,
    PRIMARY KEY  (id),
    UNIQUE KEY event_start (event_id,start_utc),
    KEY start_utc (start_utc),
    KEY parish_start (parish_id,start_utc),
    KEY event_type_term_id (event_type_term_id)
) {charset_collate};
SQL,
            'adct_pi_event_changes' => <<<'SQL'
CREATE TABLE {table_prefix}adct_pi_event_changes (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    event_id bigint(20) unsigned NOT NULL,
    candidate_id bigint(20) unsigned NULL,
    actor varchar(191) NOT NULL,
    kind varchar(20) NOT NULL,
    before_payload longtext NULL,
    after_payload longtext NULL,
    notified_at datetime NULL,
    reverted_by varchar(191) NULL,
    reverted_at datetime NULL,
    created_at datetime NOT NULL,
    updated_at datetime NOT NULL,
    PRIMARY KEY  (id),
    KEY event_created (event_id,created_at),
    KEY candidate_id (candidate_id)
) {charset_collate};
SQL,
            'adct_pi_action_tokens' => <<<'SQL'
CREATE TABLE {table_prefix}adct_pi_action_tokens (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    token_hash char(64) NOT NULL,
    purpose varchar(32) NOT NULL,
    subject_type varchar(32) NOT NULL,
    subject_id bigint(20) unsigned NOT NULL,
    email varchar(191) NULL,
    expires_at datetime NOT NULL,
    used_at datetime NULL,
    created_ip varchar(45) NULL,
    created_at datetime NOT NULL,
    updated_at datetime NOT NULL,
    PRIMARY KEY  (id),
    UNIQUE KEY token_hash (token_hash),
    KEY subject (subject_type,subject_id),
    KEY expires_at (expires_at),
    KEY purpose (purpose)
) {charset_collate};
SQL,
            'adct_pi_follow_ups' => <<<'SQL'
CREATE TABLE {table_prefix}adct_pi_follow_ups (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    parish_id bigint(20) unsigned NOT NULL,
    kind varchar(32) NOT NULL,
    channel varchar(20) NOT NULL,
    sent_at datetime NULL,
    outcome varchar(32) NULL,
    note text NULL,
    created_at datetime NOT NULL,
    updated_at datetime NOT NULL,
    PRIMARY KEY  (id),
    KEY parish_kind (parish_id,kind),
    KEY sent_at (sent_at)
) {charset_collate};
SQL,
            'adct_pi_audit_log' => <<<'SQL'
CREATE TABLE {table_prefix}adct_pi_audit_log (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    actor varchar(191) NOT NULL,
    action varchar(64) NOT NULL,
    subject_type varchar(32) NOT NULL,
    subject_id bigint(20) unsigned NULL,
    details longtext NULL,
    created_at datetime NOT NULL,
    updated_at datetime NOT NULL,
    PRIMARY KEY  (id),
    KEY actor (actor),
    KEY subject (subject_type,subject_id),
    KEY created_at (created_at)
) {charset_collate};
SQL,
            'adct_pi_mail_queue' => <<<'SQL'
CREATE TABLE {table_prefix}adct_pi_mail_queue (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    recipient varchar(191) NOT NULL,
    subject varchar(255) NOT NULL,
    body_html longtext NULL,
    body_text longtext NULL,
    priority tinyint(3) unsigned NOT NULL DEFAULT 2,
    group_key varchar(191) NULL,
    status varchar(20) NOT NULL DEFAULT 'queued',
    attempts smallint(5) unsigned NOT NULL DEFAULT 0,
    next_attempt_at datetime NULL,
    sent_at datetime NULL,
    error text NULL,
    created_at datetime NOT NULL,
    updated_at datetime NOT NULL,
    PRIMARY KEY  (id),
    KEY queue_order (status,priority,next_attempt_at),
    KEY group_key (group_key),
    KEY sent_at (sent_at)
) {charset_collate};
SQL,
        ];
    }
}
