<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion;

use JsonException;
use UnexpectedValueException;

final readonly class MailboxCheckpoint
{
    public const MAX_UID = 4294967295;

    public function __construct(
        public int $uidValidity,
        public int $lastUid,
        public bool $scanComplete = false
    ) {
        if ($uidValidity < 1 || $uidValidity > self::MAX_UID) {
            throw new UnexpectedValueException('A mailbox checkpoint needs a valid UIDVALIDITY value.');
        }

        if ($lastUid < 0 || $lastUid > self::MAX_UID) {
            throw new UnexpectedValueException('A mailbox checkpoint needs a valid last UID.');
        }
    }

    public static function fromJson(?string $json): ?self
    {
        if ($json === null || $json === '') {
            return null;
        }

        try {
            $values = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new UnexpectedValueException('The stored mailbox checkpoint is not valid JSON.', 0, $failure);
        }

        if (
            ! is_array($values)
            || ! isset($values['uidvalidity'], $values['last_uid'])
            || ! is_int($values['uidvalidity'])
            || ! is_int($values['last_uid'])
            || (array_key_exists('scan_complete', $values) && ! is_bool($values['scan_complete']))
        ) {
            throw new UnexpectedValueException('The stored mailbox checkpoint has an invalid shape.');
        }

        return new self(
            $values['uidvalidity'],
            $values['last_uid'],
            $values['scan_complete'] ?? false
        );
    }

    public function forUidValidity(int $uidValidity): self
    {
        if ($uidValidity === $this->uidValidity) {
            return $this;
        }

        return new self($uidValidity, 0);
    }

    public function advanceTo(int $uid): self
    {
        if ($uid < 1 || $uid > self::MAX_UID) {
            throw new UnexpectedValueException('A mailbox checkpoint can only advance to a valid UID.');
        }

        if ($uid <= $this->lastUid) {
            return $this;
        }

        return new self($this->uidValidity, $uid);
    }

    public function beginScan(): self
    {
        if (! $this->scanComplete) {
            return $this;
        }

        return new self($this->uidValidity, $this->lastUid);
    }

    public function completeScan(): self
    {
        if ($this->scanComplete) {
            return $this;
        }

        return new self($this->uidValidity, $this->lastUid, true);
    }

    public function toJson(): string
    {
        try {
            return json_encode([
                'uidvalidity' => $this->uidValidity,
                'last_uid' => $this->lastUid,
                'scan_complete' => $this->scanComplete,
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new UnexpectedValueException('The mailbox checkpoint could not be encoded.', 0, $failure);
        }
    }
}
