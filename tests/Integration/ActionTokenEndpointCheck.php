<?php

declare(strict_types=1);

use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Auth\ActionTokenHandlerRegistry;
use ADCT\ParishIntake\Core\Auth\ActionTokenOutcome;
use ADCT\ParishIntake\Core\Auth\ActionTokenPreview;
use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
use ADCT\ParishIntake\Core\Auth\ActionTokenRateLimiter;
use ADCT\ParishIntake\Core\Auth\ActionTokenRenewalService;
use ADCT\ParishIntake\Core\Auth\ActionTokenRenewalStatus;
use ADCT\ParishIntake\Core\Auth\ActionTokenService;
use ADCT\ParishIntake\Core\Auth\ActionTokenStatus;
use ADCT\ParishIntake\Core\Mail\MailPriority;
use ADCT\ParishIntake\Core\Mail\MailQueueEnqueueResult;
use ADCT\ParishIntake\Core\Mail\MailQueueStatus;
use ADCT\ParishIntake\Core\Mail\OutboundEmail;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\AtomicActionTokenHandlerInterface;
use ADCT\ParishIntake\Core\Ports\ActionTokenActionHandlerInterface;
use ADCT\ParishIntake\Core\Ports\MailerInterface;
use ADCT\ParishIntake\WordPress\Auth\ActionTokenEndpoint;
use ADCT\ParishIntake\WordPress\Auth\WordPressActionTokenRenewalDelivery;
use ADCT\ParishIntake\WordPress\Database\WordPressActionTokenRateLimitStore;
use ADCT\ParishIntake\WordPress\Database\WordPressActionTokenStore;
use ADCT\ParishIntake\WordPress\Database\WordPressDatabaseConnection;

final class ActionTokenEndpointCheck
{
    public static function run(callable $fail): void
    {
        global $wpdb;

        $database = new WordPressDatabaseConnection();
        $tokenStore = new WordPressActionTokenStore($database);
        $clock = new ActionTokenEndpointCheckClock();
        $tokens = new ActionTokenService($tokenStore, $clock);
        $rateLimitStore = new WordPressActionTokenRateLimitStore($database);
        $limiter = new ActionTokenRateLimiter($rateLimitStore, $clock, wp_salt('auth'));
        $mailer = new ActionTokenEndpointCheckMailer();
        $handler = new ActionTokenEndpointCheckHandler();
        $endpoint = new ActionTokenEndpoint(
            $tokens,
            new ActionTokenHandlerRegistry([$handler]),
            new ActionTokenRenewalService(
                $tokens,
                $limiter,
                new WordPressActionTokenRenewalDelivery($mailer)
            )
        );
        $check = static function (bool $condition, string $message) use ($fail): void {
            if (! $condition) {
                $fail($message);
            }
        };

        $queryVars = apply_filters('query_vars', []);
        $check(
            in_array(ActionTokenEndpoint::QUERY_VAR, $queryVars, true)
                && in_array(ActionTokenEndpoint::TOKEN_PARAM, $queryVars, true),
            'The action-token endpoint query vars were not registered.'
        );

        $testSuffix = bin2hex(random_bytes(8));
        $subjectId = random_int(1000000, 2000000);
        $email = 'action-token-' . $testSuffix . '@example.test';
        $binding = new ActionTokenBinding(
            ActionTokenPurpose::CONFIRM,
            'candidate',
            $subjectId,
            $email
        );
        $issued = $tokens->issue($binding, $clock->now()->modify('+5 minutes'));
        $beforeGet = $tokenStore->findByHash(hash('sha256', $issued->token()));
        $getResponse = $endpoint->respond('GET', $issued->token(), '', '', '', '203.0.113.91');
        $afterGet = $tokenStore->findByHash(hash('sha256', $issued->token()));
        $formAction = '';

        if (preg_match('/<form method="post" action="([^"]+)"/', $getResponse->body, $matches) === 1) {
            $formAction = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
        }

        $check(
            $getResponse->statusCode === 200
                && $beforeGet !== null
                && $afterGet !== null
                && $afterGet->usedAt === null
                && strpos($getResponse->body, 'Confirmation &lt;script&gt;') !== false
                && strpos($getResponse->body, '<script>') === false
                && strpos($getResponse->body, $email) === false
                && strpos($formAction, $issued->token()) === false
                && ($getResponse->headers()['Cache-Control'] ?? '') !== ''
                && strpos($getResponse->headers()['Cache-Control'], 'no-store') !== false
                && ($getResponse->headers()['Referrer-Policy'] ?? '') === 'no-referrer',
            'A valid GET changed state or rendered unsafe, cached, or PII-bearing confirmation output.'
        );

        $nonce = self::nonceFrom($getResponse->body, $fail);
        $postResponse = $endpoint->respond(
            'POST',
            '',
            $issued->token(),
            'perform',
            $nonce,
            '203.0.113.91'
        );
        $replayResponse = $endpoint->respond(
            'POST',
            '',
            $issued->token(),
            'perform',
            $nonce,
            '203.0.113.91'
        );

        $check(
            $postResponse->statusCode === 200
                && strpos($postResponse->body, 'Action completed.') !== false
                && $handler->performCount === 1
                && $tokens->inspect($issued->token())->status === ActionTokenStatus::USED
                && strpos($replayResponse->body, 'already been used') !== false
                && $handler->performCount === 1,
            'A token did not act exactly once through the nonce-protected POST endpoint.'
        );

        $recoveringHandler = new ActionTokenEndpointCheckRecoveringHandler();
        $recoveringEndpoint = new ActionTokenEndpoint(
            $tokens,
            new ActionTokenHandlerRegistry([$recoveringHandler]),
            new ActionTokenRenewalService(
                $tokens,
                $limiter,
                new WordPressActionTokenRenewalDelivery($mailer)
            )
        );
        $recoveringBinding = new ActionTokenBinding(
            ActionTokenPurpose::CONFIRM,
            'candidate',
            $subjectId + 4,
            'recover-' . $testSuffix . '@example.test'
        );
        $recoveringIssued = $tokens->issue($recoveringBinding, $clock->now()->modify('+5 minutes'));
        $recoveringNonce = self::nonceFrom(
            $recoveringEndpoint->respond('GET', $recoveringIssued->token(), '', '', '', '203.0.113.91')->body,
            $fail
        );
        $recoveringFailure = $recoveringEndpoint->respond(
            'POST',
            '',
            $recoveringIssued->token(),
            'perform',
            $recoveringNonce,
            '203.0.113.91'
        );
        $recoveringRetry = $recoveringEndpoint->respond(
            'POST',
            '',
            $recoveringIssued->token(),
            'perform',
            $recoveringNonce,
            '203.0.113.91'
        );

        $check(
            $recoveringFailure->statusCode === 503
                && strpos($recoveringFailure->body, 'may already be recorded') !== false
                && strpos($recoveringFailure->body, 'no new decision') === false
                && $tokens->inspect($recoveringIssued->token())->status === ActionTokenStatus::USED
                && $recoveringHandler->performCount === 1
                && $recoveringHandler->recoverCount === 1
                && $recoveringRetry->statusCode === 200
                && strpos($recoveringRetry->body, 'Action completed after recovery.') !== false
                && strpos($recoveringRetry->body, 'View published event') !== false,
            'A failed confirmation did not recover safely on replay or report the correct retry guidance.'
        );

        $concurrentBinding = new ActionTokenBinding(
            ActionTokenPurpose::CONFIRM,
            'candidate',
            $subjectId + 3,
            'concurrent-' . $testSuffix . '@example.test'
        );
        $concurrentToken = $tokens->issue(
            $concurrentBinding,
            $clock->now()->modify('+5 minutes')
        );
        $concurrentTokenHash = hash('sha256', $concurrentToken->token());
        $concurrentRecordWasConsumedOnce = self::runConcurrentConsume(
            $wpdb,
            $concurrentTokenHash,
            $concurrentBinding,
            $clock->now()
        );
        $concurrentRecord = $tokenStore->findByHash($concurrentTokenHash);

        $check(
            $concurrentRecordWasConsumedOnce
                && $concurrentRecord !== null
                && $concurrentRecord->usedAt !== null,
            'Concurrent action-token consumption did not produce exactly one atomic winner.'
        );

        $unverifiedBinding = new ActionTokenBinding(
            ActionTokenPurpose::CONFIRM,
            'candidate',
            $subjectId + 1,
            $email
        );
        $unverified = $tokens->issue($unverifiedBinding);
        $unverifiedResponse = $endpoint->respond(
            'POST',
            '',
            $unverified->token(),
            'perform',
            'invalid-nonce',
            '203.0.113.91'
        );
        $unverifiedRecord = $tokenStore->findByHash(hash('sha256', $unverified->token()));

        $check(
            $unverifiedResponse->statusCode === 403
                && $unverifiedRecord !== null
                && $unverifiedRecord->usedAt === null
                && $handler->performCount === 1,
            'An invalid nonce consumed an action token or invoked its handler.'
        );

        $renewalSubjectId = $subjectId + 2;
        $renewalEmail = 'renewal-' . $testSuffix . '@example.test';
        $renewalBinding = new ActionTokenBinding(
            ActionTokenPurpose::CONFIRM,
            'candidate',
            $renewalSubjectId,
            $renewalEmail
        );
        $expired = $tokens->issue($renewalBinding, $clock->now()->modify('+1 minute'));
        $clock->advance('+1 minute');
        $renewalIpAddress = '203.0.113.' . random_int(1, 254);
        $emailScopeHash = hash_hmac('sha256', 'email:' . $renewalEmail, wp_salt('auth'));
        $renewalIpScopeHash = hash_hmac(
            'sha256',
            'ip:' . bin2hex((string) inet_pton($renewalIpAddress)),
            wp_salt('auth')
        );
        $rateLimitTable = $wpdb->prefix . 'adct_pi_action_token_rate_limits';
        $rateLimitsBeforeGet = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$rateLimitTable} WHERE scope_hash IN (%s, %s)",
            $emailScopeHash,
            $renewalIpScopeHash
        ));
        $expiredResponse = $endpoint->respond(
            'GET',
            $expired->token(),
            '',
            '',
            '',
            $renewalIpAddress
        );
        $renewalNonce = self::nonceFrom($expiredResponse->body, $fail);
        $rateLimitsAfterGet = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$rateLimitTable} WHERE scope_hash IN (%s, %s)",
            $emailScopeHash,
            $renewalIpScopeHash
        ));

        $check(
            strpos($expiredResponse->body, 'Send me a new link') !== false
                && $rateLimitsBeforeGet === 0
                && $rateLimitsAfterGet === 0,
            'Opening an expired link did not show the renewal option read-only.'
        );

        for ($attempt = 0; $attempt < 4; ++$attempt) {
            $renewalResponse = $endpoint->respond(
                'POST',
                '',
                $expired->token(),
                'renew',
                $renewalNonce,
                $renewalIpAddress
            );
            $check(
                $renewalResponse->statusCode === 200
                    && strpos($renewalResponse->body, 'If another link can be sent') !== false,
                'The renewal endpoint did not return its non-enumerating response.'
            );
        }

        $renewalTokenCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}adct_pi_action_tokens "
            . 'WHERE purpose = %s AND subject_type = %s AND subject_id = %d AND email = %s',
            ActionTokenPurpose::CONFIRM->value,
            'candidate',
            $renewalSubjectId,
            $renewalEmail
        ));
        $rateLimitRows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT scope_hash, hit_count FROM {$rateLimitTable} WHERE scope_hash IN (%s, %s)",
            $emailScopeHash,
            $renewalIpScopeHash
        ), ARRAY_A);
        $rateHits = [];

        foreach ($rateLimitRows as $row) {
            $rateHits[(string) ($row['scope_hash'] ?? '')] = (int) ($row['hit_count'] ?? 0);
        }

        $check(
            count($mailer->messages) === ActionTokenRateLimiter::EMAIL_REQUEST_LIMIT
                && $renewalTokenCount === 1 + ActionTokenRateLimiter::EMAIL_REQUEST_LIMIT
                && ($rateHits[$emailScopeHash] ?? 0) === ActionTokenRateLimiter::EMAIL_REQUEST_LIMIT + 1
                && ($rateHits[$renewalIpScopeHash] ?? 0) === ActionTokenRateLimiter::EMAIL_REQUEST_LIMIT + 1,
            'The renewal flow did not enforce its atomic per-email rate limit.'
        );

        foreach ($mailer->messages as $message) {
            $messageUrl = trim(substr($message->textBody, strrpos($message->textBody, "\n\n") + 2));
            $query = parse_url($messageUrl, PHP_URL_QUERY);
            parse_str(is_string($query) ? $query : '', $queryParameters);
            $renewalSecret = (string) ($queryParameters[ActionTokenEndpoint::TOKEN_PARAM] ?? '');

            $check(
                $message->recipient === $renewalEmail
                    && $message->priority === MailPriority::LOGIN_OR_CONFIRMATION
                    && $renewalSecret !== ''
                    && $message->groupKey === 'action-token-renewal:' . hash('sha256', $renewalSecret)
                    && strpos((string) $message->groupKey, $renewalSecret) === false
                    && strpos($message->htmlBody, $renewalEmail) === false,
                'Renewal delivery bypassed the queue priority or included recipient data in its body/key.'
            );
        }

        $freshAdmissionWindowStart = $clock
            ->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->setTime(0, 0);
        $freshAdmissionScopeHashes = [];
        $freshAdmissionsAllowed = true;

        for ($index = 0; $index < ActionTokenRateLimiter::IP_REQUEST_LIMIT + 5; ++$index) {
            $scopeHash = hash_hmac(
                'sha256',
                'fresh-action-token-scope:' . $testSuffix . ':' . $index,
                wp_salt('auth')
            );
            $freshAdmissionScopeHashes[] = $scopeHash;
            $allowed = $rateLimitStore->consume(
                $scopeHash,
                $freshAdmissionWindowStart,
                ActionTokenRateLimiter::IP_REQUEST_LIMIT
            );
            $freshAdmissionsAllowed = $allowed && $freshAdmissionsAllowed;
        }

        foreach ($freshAdmissionScopeHashes as $scopeHash) {
            $wpdb->delete($rateLimitTable, ['scope_hash' => $scopeHash]);
        }

        $check(
            $freshAdmissionsAllowed,
            'A fresh rate-limit scope was denied after more than twenty prior bucket inserts.'
        );

        $ipTestClock = new ActionTokenEndpointCheckClock();
        $ipTokens = new ActionTokenService($tokenStore, $ipTestClock);
        $ipMailer = new ActionTokenEndpointCheckMailer();
        $ipRenewals = new ActionTokenRenewalService(
            $ipTokens,
            new ActionTokenRateLimiter($rateLimitStore, $ipTestClock, wp_salt('auth')),
            new WordPressActionTokenRenewalDelivery($ipMailer)
        );
        $ipAddress = '198.51.100.' . random_int(1, 254);
        $ipTokenRows = [];
        $ipScopeHashes = [];
        $ipEmailPrefix = 'ip-renewal-' . $testSuffix . '-';

        for ($index = 0; $index <= ActionTokenRateLimiter::IP_REQUEST_LIMIT; ++$index) {
            $ipEmail = $ipEmailPrefix . $index . '@example.test';
            $ipBinding = new ActionTokenBinding(
                ActionTokenPurpose::CONFIRM,
                'candidate',
                $renewalSubjectId + 100 + $index,
                $ipEmail
            );
            $ipTokenRows[] = [
                $ipTokens->issue($ipBinding, $ipTestClock->now()->modify('+1 minute'))->token(),
                $ipEmail,
            ];
            $ipScopeHashes[] = hash_hmac('sha256', 'email:' . $ipEmail, wp_salt('auth'));
        }

        $ipTestClock->advance('+1 minute');
        $ipScopeHash = hash_hmac(
            'sha256',
            'ip:' . bin2hex((string) inet_pton($ipAddress)),
            wp_salt('auth')
        );

        foreach ($ipTokenRows as $index => [$ipToken]) {
            $renewalStatus = $ipRenewals->request($ipToken, $ipAddress);
            $expectedStatus = $index < ActionTokenRateLimiter::IP_REQUEST_LIMIT
                ? ActionTokenRenewalStatus::REQUESTED
                : ActionTokenRenewalStatus::RATE_LIMITED;

            if ($renewalStatus !== $expectedStatus) {
                $emailHits = $wpdb->get_var($wpdb->prepare(
                    "SELECT hit_count FROM {$rateLimitTable} WHERE scope_hash = %s",
                    $ipScopeHashes[$index]
                ));
                $ipHits = $wpdb->get_var($wpdb->prepare(
                    "SELECT hit_count FROM {$rateLimitTable} WHERE scope_hash = %s",
                    $ipScopeHash
                ));
                $check(
                    false,
                    'The database-backed IP rate limit returned ' . $renewalStatus->value
                        . ' for request ' . ($index + 1) . '; expected ' . $expectedStatus->value
                        . ' (email hits: ' . (string) $emailHits . '; IP hits: ' . (string) $ipHits . ').'
                );
            }
        }

        $ipTokenCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}adct_pi_action_tokens WHERE email LIKE %s",
            $ipEmailPrefix . '%@example.test'
        ));
        $ipLimitRow = $wpdb->get_row($wpdb->prepare(
            "SELECT hit_count FROM {$rateLimitTable} WHERE scope_hash = %s",
            $ipScopeHash
        ), ARRAY_A);

        $check(
            count($ipMailer->messages) === ActionTokenRateLimiter::IP_REQUEST_LIMIT
                && $ipTokenCount === 2 * ActionTokenRateLimiter::IP_REQUEST_LIMIT + 1
                && is_array($ipLimitRow)
                && (int) ($ipLimitRow['hit_count'] ?? 0) === ActionTokenRateLimiter::IP_REQUEST_LIMIT + 1,
            'The database-backed IP limit enqueued an extra renewal after its threshold.'
        );

        $wpdb->delete(
            $wpdb->prefix . 'adct_pi_action_tokens',
            ['subject_type' => 'candidate', 'subject_id' => $subjectId]
        );
        $wpdb->delete(
            $wpdb->prefix . 'adct_pi_action_tokens',
            ['subject_type' => 'candidate', 'subject_id' => $subjectId + 3]
        );
        $wpdb->delete(
            $wpdb->prefix . 'adct_pi_action_tokens',
            ['subject_type' => 'candidate', 'subject_id' => $subjectId + 1]
        );
        $wpdb->delete(
            $wpdb->prefix . 'adct_pi_action_tokens',
            ['subject_type' => 'candidate', 'subject_id' => $renewalSubjectId]
        );
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$rateLimitTable} WHERE scope_hash IN (%s, %s)",
            $emailScopeHash,
            $renewalIpScopeHash
        ));
        foreach ($ipScopeHashes as $scopeHash) {
            $wpdb->delete($rateLimitTable, ['scope_hash' => $scopeHash]);
        }
        $wpdb->delete($wpdb->prefix . 'adct_pi_action_token_rate_limits', ['scope_hash' => $ipScopeHash]);
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}adct_pi_action_tokens WHERE email LIKE %s",
            $ipEmailPrefix . '%@example.test'
        ));
    }

    private static function runConcurrentConsume(
        \wpdb $database,
        string $tokenHash,
        ActionTokenBinding $binding,
        DateTimeImmutable $now
    ): bool {
        if (! function_exists('mysqli_poll') || ! defined('MYSQLI_ASYNC')) {
            return false;
        }

        $newConnection = static function () use ($database): \wpdb {
            $connection = new \wpdb(
                (string) $database->dbuser,
                (string) $database->dbpassword,
                (string) $database->dbname,
                (string) $database->dbhost
            );
            $connection->set_prefix((string) $database->prefix);

            return $connection;
        };
        $locker = $newConnection();
        $first = $newConnection();
        $second = $newConnection();
        $lockHeld = false;

        try {
            $firstHandle = $first->dbh;
            $secondHandle = $second->dbh;

            if (! $firstHandle instanceof \mysqli || ! $secondHandle instanceof \mysqli) {
                return false;
            }

            $table = '`' . $database->prefix . 'adct_pi_action_tokens`';
            $nowUtc = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $query = $database->prepare(
                "UPDATE {$table} SET used_at = %s, updated_at = %s "
                . 'WHERE token_hash = %s AND purpose = %s AND subject_type = %s '
                . 'AND subject_id = %d AND email = %s AND used_at IS NULL AND expires_at > %s',
                $nowUtc,
                $nowUtc,
                $tokenHash,
                $binding->purpose->value,
                $binding->subjectType,
                $binding->subjectId,
                $binding->email,
                $nowUtc
            );
            $lockQuery = $locker->prepare(
                "SELECT token_hash FROM {$table} WHERE token_hash = %s FOR UPDATE",
                $tokenHash
            );

            if (
                $locker->query('START TRANSACTION') === false
                || $locker->get_var($lockQuery) !== $tokenHash
            ) {
                return false;
            }

            $lockHeld = true;

            if (
                $firstHandle->query($query, MYSQLI_ASYNC) !== true
                || $secondHandle->query($query, MYSQLI_ASYNC) !== true
            ) {
                return false;
            }

            usleep(100000);

            if ($locker->query('COMMIT') === false) {
                return false;
            }

            $lockHeld = false;
            $pending = [$firstHandle, $secondHandle];
            $affectedRows = [];

            for ($attempt = 0; $pending !== [] && $attempt < 10; ++$attempt) {
                $ready = $pending;
                $errors = [];
                $rejected = [];
                $readyCount = mysqli_poll($ready, $errors, $rejected, 1);

                if ($readyCount === false || $errors !== [] || $rejected !== []) {
                    return false;
                }

                foreach ($ready as $readyConnection) {
                    $result = $readyConnection->reap_async_query();

                    if ($result === false) {
                        return false;
                    }

                    if ($result instanceof \mysqli_result) {
                        $result->free();
                    }

                    $affectedRows[] = $readyConnection->affected_rows;
                    $pending = array_values(array_filter(
                        $pending,
                        static fn (\mysqli $pendingConnection): bool => $pendingConnection !== $readyConnection
                    ));
                }
            }

            sort($affectedRows);

            return $pending === [] && $affectedRows === [0, 1];
        } finally {
            if ($lockHeld) {
                $locker->query('ROLLBACK');
            }

            $first->close();
            $second->close();
            $locker->close();
        }
    }

    private static function nonceFrom(string $html, callable $fail): string
    {
        if (preg_match('/name="adct_token_nonce" value="([^"]+)"/', $html, $matches) !== 1) {
            $fail('The action-token form did not include its POST nonce.');
        }

        return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    }
}

final class ActionTokenEndpointCheckHandler implements ActionTokenActionHandlerInterface
{
    public int $performCount = 0;

    public function purpose(): ActionTokenPurpose
    {
        return ActionTokenPurpose::CONFIRM;
    }

    public function preview(ActionTokenBinding $binding): ?ActionTokenPreview
    {
        return new ActionTokenPreview(
            'Confirmation <script>',
            'Review and confirm this fictional item.',
            'Confirm',
            ['Item <private>']
        );
    }

    public function perform(ActionTokenBinding $binding): ActionTokenOutcome
    {
        ++$this->performCount;

        return new ActionTokenOutcome('Action completed.');
    }
}

final class ActionTokenEndpointCheckRecoveringHandler implements AtomicActionTokenHandlerInterface
{
    public int $performCount = 0;
    public int $recoverCount = 0;
    private bool $failedOnce = false;

    public function purpose(): ActionTokenPurpose
    {
        return ActionTokenPurpose::CONFIRM;
    }

    public function preview(ActionTokenBinding $binding): ?ActionTokenPreview
    {
        return new ActionTokenPreview(
            'Confirmation <script>',
            'Review and confirm this fictional item.',
            'Confirm',
            ['Item <private>']
        );
    }

    public function perform(ActionTokenBinding $binding): ActionTokenOutcome
    {
        return new ActionTokenOutcome('Action completed.');
    }

    public function performAtomic(
        ActionTokenBinding $binding,
        string $token,
        ActionTokenService $tokens,
        string $reason
    ): ActionTokenOutcome {
        ++$this->performCount;

        $consumption = $tokens->consume($token, $binding);
        if ($consumption->status !== ActionTokenStatus::CONSUMED) {
            throw new RuntimeException('The confirmation token could not be consumed.');
        }

        if (! $this->failedOnce) {
            $this->failedOnce = true;
            throw new RuntimeException('The confirmation publish step failed after the decision was recorded.');
        }

        return $this->recover($binding);
    }

    public function recover(ActionTokenBinding $binding): ActionTokenOutcome
    {
        ++$this->recoverCount;

        return new ActionTokenOutcome(
            'Action completed after recovery.',
            ['https://example.test/recovered']
        );
    }
}

final class ActionTokenEndpointCheckMailer implements MailerInterface
{
    /**
     * @var list<OutboundEmail>
     */
    public array $messages = [];

    public function enqueue(OutboundEmail $email): MailQueueEnqueueResult
    {
        $this->messages[] = $email;

        return new MailQueueEnqueueResult(
            count($this->messages),
            MailQueueStatus::QUEUED,
            false
        );
    }
}

final class ActionTokenEndpointCheckClock implements ClockInterface
{
    private DateTimeImmutable $instant;

    public function __construct()
    {
        $this->instant = new DateTimeImmutable(
            '2026-09-25 02:00:00',
            new DateTimeZone('Africa/Johannesburg')
        );
    }

    public function now(): DateTimeImmutable
    {
        return $this->instant;
    }

    public function advance(string $interval): void
    {
        $this->instant = $this->instant->modify($interval);
    }
}
