<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Directory;

use ADCT\ParishIntake\Core\Directory\DirectorySnapshot;
use ADCT\ParishIntake\Core\Directory\SenderParishSuggester;
use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\ParseOutcome;
use ADCT\ParishIntake\Core\Parsing\ParseResult;
use ADCT\ParishIntake\Core\Ports\DirectorySnapshotProviderInterface;
use PHPUnit\Framework\TestCase;

final class SenderParishSuggesterTest extends TestCase
{
    public function testSignatureOutranksAnEventHostedAtAnotherParish(): void
    {
        $suggester = $this->suggester();
        $message = new Message('email', '1', 'office@example.test', '', '', 'Supper at St Beta Parish', [], null, [], '', 'St Alpha Parish office');
        self::assertSame([1, 'signature'], $suggester->suggest($message, $this->outcome([2])));
    }

    public function testConflictingCandidateParishesAndSharedDomainRemainAmbiguous(): void
    {
        $suggester = $this->suggester();
        $message = new Message('email', '1', 'office@example.test', '', '', 'Upcoming events');
        self::assertSame([null, null], $suggester->suggest($message, $this->outcome([1, 2])));
        self::assertSame([null, null], $suggester->suggest($message, $this->outcome([])));
    }

    public function testParserBodyAndUniqueVerifiedDomainAreOnlySuggestions(): void
    {
        $suggester = $this->suggester();
        self::assertSame(
            [2, 'parser'],
            $suggester->suggest(new Message('email', '1', 'office@unique.test', '', '', ''), $this->outcome([2]))
        );
        self::assertSame(
            [1, 'body'],
            $suggester->suggest(new Message('email', '1', 'office@unique.test', '', '', 'St Alpha Parish'), $this->outcome([]))
        );
        self::assertSame(
            [1, 'domain'],
            $suggester->suggest(new Message('email', '1', 'office@unique.test', '', '', ''), $this->outcome([]))
        );
    }

    private function suggester(): SenderParishSuggester
    {
        $snapshot = new DirectorySnapshot(
            [
                ['id' => 1, 'name' => 'St Alpha Parish', 'status' => 'active'],
                ['id' => 2, 'name' => 'St Beta Parish', 'status' => 'active'],
            ],
            [],
            [
                ['parish_id' => 1, 'email' => 'verified@unique.test', 'trust' => 'verified'],
                ['parish_id' => 2, 'email' => 'verified@example.test', 'trust' => 'verified'],
                ['parish_id' => 1, 'email' => 'other@example.test', 'trust' => 'verified'],
            ]
        );
        return new SenderParishSuggester(new class ($snapshot) implements DirectorySnapshotProviderInterface {
            public function __construct(private DirectorySnapshot $snapshot)
            {
            }
            public function getSnapshot(): DirectorySnapshot
            {
                return $this->snapshot;
            }
        });
    }

    /** @param list<int> $ids */
    private function outcome(array $ids): ParseOutcome
    {
        $candidates = [];
        foreach ($ids as $id) {
            $candidate = new ParseResult();
            $candidate->setField('parish_id', $id);
            $candidates[] = $candidate;
        }
        return new ParseOutcome($candidates, [], [], [], $candidates[0] ?? new ParseResult());
    }
}
