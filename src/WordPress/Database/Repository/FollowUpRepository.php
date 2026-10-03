<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database\Repository;

use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\FollowUpRepositoryInterface;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

final class FollowUpRepository implements FollowUpRepositoryInterface
{
    private const KINDS = [
        self::KIND_APPROVAL_REMINDER,
    ];

    private const CHANNELS = [
        self::CHANNEL_EMAIL,
    ];

    private ?string $table = null;

    public function __construct(
        private readonly DatabaseConnectionInterface $database,
        private readonly ClockInterface $clock
    ) {
    }

    public function record(
        ?int $parishId,
        string $kind,
        string $channel,
        ?string $note = null,
        ?string $sentAt = null,
        ?string $outcome = null
    ): bool {
        if ($parishId !== null && $parishId < 1) {
            throw new InvalidArgumentException('A follow-up parish must be a real parish.');
        }
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('The follow-up kind is not recognised.');
        }
        if (! in_array($channel, self::CHANNELS, true)) {
            throw new InvalidArgumentException('The follow-up channel is not recognised.');
        }

        $now = $this->utcNow();

        // $wpdb substitutes 0 for a null %d, which would silently attribute the
        // follow-up to a parish that does not exist, so the two cases use
        // separate statement shapes rather than one parameterised placeholder.
        if ($parishId === null) {
            $placeholders = 'NULL,%s,%s,%s,%s,%s,%s,%s';
            $arguments = [$kind, $channel, $sentAt ?? $now, $outcome, $note, $now, $now];
        } else {
            $placeholders = '%d,%s,%s,%s,%s,%s,%s,%s';
            $arguments = [$parishId, $kind, $channel, $sentAt ?? $now, $outcome, $note, $now, $now];
        }

        $this->database->clearLastError();
        $inserted = $this->database->query($this->database->prepare(
            'INSERT INTO ' . $this->table() . ' '
            . '(parish_id,kind,channel,sent_at,outcome,note,created_at,updated_at) '
            . 'VALUES (' . $placeholders . ')',
            ...$arguments
        ));

        if ($inserted === false || $this->database->lastError() !== '') {
            throw new RuntimeException('The follow-up could not be recorded.');
        }

        return true;
    }

    /**
     * Resolved on first use rather than in the constructor, because the
     * constructor also runs under the release bootstrap check with no
     * WordPress loaded, and the prefix is only reachable through the database.
     */
    private function table(): string
    {
        if ($this->table === null) {
            $prefix = $this->database->prefix();
            if (preg_match('/\A[A-Za-z0-9_]+\z/D', $prefix) !== 1) {
                throw new InvalidArgumentException('The database prefix is invalid.');
            }
            $this->table = "`{$prefix}adct_pi_follow_ups`";
        }

        return $this->table;
    }

    private function utcNow(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
