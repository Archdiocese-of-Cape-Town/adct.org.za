<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion;

enum MailboxConnectionTestStatus: string
{
    case CONNECTED = 'connected';
    case PROCESSED_FOLDER_MISSING = 'processed_folder_missing';
    case PROCESSED_FOLDER_CREATED = 'processed_folder_created';
    case PASSWORD_MISSING = 'password_missing';
    case AUTHENTICATION_FAILED = 'authentication_failed';
    case CONNECTION_FAILED = 'connection_failed';
    case TLS_FAILED = 'tls_failed';
    case TIMEOUT = 'timeout';
    case PROTOCOL_ERROR = 'protocol_error';
}
