<?php

namespace ADCT\ParishIntake\Database;

use ADCT\ParishIntake\Parsing\Input\Message;
use ADCT\ParishIntake\Parsing\ParseResult;

final class Schema
{
    public function tableName(): string
    {
        global $wpdb;

        return isset($wpdb) ? $wpdb->prefix . 'adct_parish_intake_items' : 'wp_adct_parish_intake_items';
    }

    public function install(): void
    {
        global $wpdb;

        if (! isset($wpdb)) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $table = $this->tableName();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_type varchar(50) NOT NULL DEFAULT 'email',
            source_identifier varchar(191) NOT NULL DEFAULT '',
            sender_name varchar(191) NULL,
            sender_email varchar(191) NULL,
            subject text NULL,
            raw_text longtext NULL,
            normalized_text longtext NULL,
            classification varchar(50) NOT NULL DEFAULT 'unknown',
            parish_name varchar(191) NULL,
            title varchar(255) NULL,
            extracted_payload longtext NULL,
            recurrence_payload longtext NULL,
            parser_version varchar(50) NOT NULL DEFAULT '0.1.0',
            parser_strategy_used varchar(255) NOT NULL DEFAULT '',
            extraction_confidence decimal(5,2) NOT NULL DEFAULT 0.00,
            extraction_notes longtext NULL,
            ai_used tinyint(1) NOT NULL DEFAULT 0,
            ai_provider varchar(100) NULL,
            reprocess_needed tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY sender_email (sender_email(100)),
            KEY classification (classification),
            KEY created_at (created_at)
        ) {$charset};";

        dbDelta($sql);
    }

    public function insertMessageResult(Message $message, ParseResult $result): ?int
    {
        global $wpdb;

        if (! isset($wpdb)) {
            return null;
        }

        $table = $this->tableName();
        $payload = $result->toArray();
        $timestamp = function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s');

        $wpdb->insert(
            $table,
            [
                'source_type' => $message->getSourceType(),
                'source_identifier' => $message->getSourceIdentifier(),
                'sender_name' => $message->getSenderName(),
                'sender_email' => $message->getSenderEmail(),
                'subject' => $message->getSubject(),
                'raw_text' => $message->getBody(),
                'normalized_text' => $result->getNormalizedText(),
                'classification' => $result->getClassification(),
                'parish_name' => (string) $result->getField('parish_name'),
                'title' => (string) $result->getField('title'),
                'extracted_payload' => wp_json_encode($payload['fields'], JSON_PRETTY_PRINT),
                'recurrence_payload' => wp_json_encode($payload['recurrence'], JSON_PRETTY_PRINT),
                'parser_version' => $result->getParserVersion(),
                'parser_strategy_used' => implode(', ', $result->getStrategies()),
                'extraction_confidence' => $result->getConfidence(),
                'extraction_notes' => wp_json_encode($payload['notes'], JSON_PRETTY_PRINT),
                'ai_used' => $result->usedAi() ? 1 : 0,
                'ai_provider' => $result->getAiProvider(),
                'reprocess_needed' => $result->needsReprocess() ? 1 : 0,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%d', '%s', '%d', '%s', '%s']
        );

        return $wpdb->insert_id ? (int) $wpdb->insert_id : null;
    }

    public function fetchRecent(int $limit = 25): array
    {
        global $wpdb;

        if (! isset($wpdb)) {
            return [];
        }

        $table = $this->tableName();
        $sql = $wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit);

        return (array) $wpdb->get_results($sql, ARRAY_A);
    }
}
