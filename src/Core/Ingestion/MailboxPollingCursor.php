<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion;

use JsonException;
use UnexpectedValueException;

final readonly class MailboxPollingCursor
{
    /**
     * @param list<int> $idleSourceIds
     */
    public function __construct(
        public int $lastSourceId = 0,
        public array $idleSourceIds = []
    ) {
        if ($lastSourceId < 0) {
            throw new UnexpectedValueException('A polling cursor cannot contain a negative source ID.');
        }

        foreach ($idleSourceIds as $sourceId) {
            if (! is_int($sourceId) || $sourceId < 1) {
                throw new UnexpectedValueException('A polling cursor contains an invalid source ID.');
            }
        }
    }

    public static function fromJson(?string $json): self
    {
        if ($json === null || $json === '') {
            return new self();
        }

        try {
            $values = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new UnexpectedValueException('The polling cursor is not valid JSON.', 0, $failure);
        }

        if (
            ! is_array($values)
            || ! isset($values['last_source_id'], $values['idle_source_ids'])
            || ! is_int($values['last_source_id'])
            || ! is_array($values['idle_source_ids'])
        ) {
            throw new UnexpectedValueException('The polling cursor has an invalid shape.');
        }

        return new self(
            $values['last_source_id'],
            array_values($values['idle_source_ids'])
        );
    }

    /**
     * @param list<int> $sourceIds
     */
    public function nextSourceId(array $sourceIds): ?int
    {
        $sourceIds = $this->validSourceIds($sourceIds);
        $unvisited = array_values(array_filter(
            $sourceIds,
            fn (int $sourceId): bool => ! in_array($sourceId, $this->idleSourceIds, true)
        ));

        if ($unvisited === []) {
            return null;
        }

        foreach ($unvisited as $sourceId) {
            if ($sourceId > $this->lastSourceId) {
                return $sourceId;
            }
        }

        return $unvisited[0];
    }

    public function afterIdleCheck(int $sourceId): self
    {
        if ($sourceId < 1) {
            throw new UnexpectedValueException('A polling cursor needs a positive source ID.');
        }

        $idleSourceIds = $this->idleSourceIds;

        if (! in_array($sourceId, $idleSourceIds, true)) {
            $idleSourceIds[] = $sourceId;
            sort($idleSourceIds, SORT_NUMERIC);
        }

        return new self($sourceId, $idleSourceIds);
    }

    public function afterWork(int $sourceId): self
    {
        if ($sourceId < 1) {
            throw new UnexpectedValueException('A polling cursor needs a positive source ID.');
        }

        return new self($sourceId);
    }

    /**
     * @param list<int> $sourceIds
     */
    public function hasVisitedAll(array $sourceIds): bool
    {
        foreach ($this->validSourceIds($sourceIds) as $sourceId) {
            if (! in_array($sourceId, $this->idleSourceIds, true)) {
                return false;
            }
        }

        return $sourceIds !== [];
    }

    public function finishSweep(): self
    {
        return new self($this->lastSourceId);
    }

    public function toJson(): string
    {
        try {
            return json_encode([
                'last_source_id' => $this->lastSourceId,
                'idle_source_ids' => array_values($this->idleSourceIds),
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new UnexpectedValueException('The polling cursor could not be encoded.', 0, $failure);
        }
    }

    /**
     * @param list<int> $sourceIds
     * @return list<int>
     */
    private function validSourceIds(array $sourceIds): array
    {
        foreach ($sourceIds as $sourceId) {
            if (! is_int($sourceId) || $sourceId < 1) {
                throw new UnexpectedValueException('The mailbox source list contains an invalid source ID.');
            }
        }

        sort($sourceIds, SORT_NUMERIC);

        return array_values(array_unique($sourceIds));
    }
}
