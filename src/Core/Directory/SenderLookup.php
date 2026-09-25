<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Directory;

use LogicException;

final class SenderLookup
{
    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public static function fromRows(string $email, array $rows): SenderLookupResult
    {
        if ($rows === []) {
            return new SenderLookupResult($email, SenderTrust::UNKNOWN, []);
        }

        $trustStates = [];
        $parishIds = [];

        foreach ($rows as $row) {
            $trust = (string) ($row['trust'] ?? '');

            if (! SenderTrust::isValid($trust)) {
                throw new LogicException('A parish contact has an invalid trust state.');
            }

            $trustStates[$trust] = true;
            $parishId = (int) ($row['parish_id'] ?? 0);

            if ($parishId > 0) {
                $parishIds[] = $parishId;
            }
        }

        if (count($trustStates) !== 1) {
            throw new LogicException('A sender address has inconsistent trust states across parish links.');
        }

        $parishIds = array_values(array_unique($parishIds));
        sort($parishIds, SORT_NUMERIC);

        return new SenderLookupResult($email, (string) array_key_first($trustStates), $parishIds);
    }
}
