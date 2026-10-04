<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Auth;

use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
use ADCT\ParishIntake\Core\Auth\ActionTokenService;
use ADCT\ParishIntake\Core\Auth\ActionTokenStatus;
use ADCT\ParishIntake\Tests\Support\NotifyModeClock;
use ADCT\ParishIntake\Tests\Support\NotifyModeDatabase;
use PHPUnit\Framework\TestCase;

/**
 * The layer-3 approver-decisions check resolves a link in the approval notice by
 * the purpose its click is supposed to have, not by where it sits in the body.
 * That resolution is a loop over `$tokens->inspect()`, and this pins it offline:
 * the layer-3 job can only run on wp-env, so without this a check that silently
 * returned the wrong token would only ever be found by CI.
 *
 * Two shapes in that mail defeat a lookup, and both reached layer 3 in turn:
  *
  * 1. ApprovalNoticeJob::deliver() prepends the digest-choice link (issue #169)
  *    ahead of the Approve/Reject/Edit links, so the approve token stopped being
  *    the first token in the body. Position is not an identity.
  * 2. The same deliver() loop mints Approve/Reject/Edit *per candidate*, so a
  *    grouped mail holds several tokens of each purpose bound to different
  *    candidates. Purpose alone is not an identity either: matching on it alone
  *    returns a token that resolves, resolves successfully, and acts on the
  *    wrong event.
  *
  * The general rule both cases establish: a lookup key must include every field
  * that distinguishes the intended subject. Purpose says what the link does; the
  * subject id says which thing it acts on; a token belonging to an adjacent
  * candidate is not an acceptable neighbour to borrow.
  */
 final class PurposeResolvedTokenLookupTest extends TestCase
 {
     private const FIRST_CANDIDATE = 1111;

     private const SECOND_CANDIDATE = 4242;

     private ActionTokenService $tokens;

    private NotifyModeDatabase $store;

    protected function setUp(): void
    {
        $this->store = new NotifyModeDatabase();
        $this->tokens = new ActionTokenService(
            $this->store,
            new NotifyModeClock('2026-06-01 09:00:00')
        );
    }

    /**
     * The same loop the integration check runs, reproduced here so it can be
     * tested without wp-env.
     *
         * When a subject id is given it must match: a token for a different
         * candidate is not the one being looked for, however well its purpose fits.
         *
         * @param list<string> $links
         */
        private function linkFor(array $links, ActionTokenPurpose $want, ?int $subjectId = null): ?string
        {
            foreach ($links as $link) {
                $binding = $this->tokens->inspect((string) $link)->binding;
                if ($binding === null || $binding->purpose !== $want) {
                    continue;
                }
                if ($subjectId !== null && $binding->subjectId !== $subjectId) {
                    continue;
                }

                return (string) $link;
            }

            return null;
        }

        private function issue(ActionTokenPurpose $purpose, int $candidate = self::SECOND_CANDIDATE): string
        {
            return $this->tokens->issue(
                new ActionTokenBinding($purpose, 'event_candidate', $candidate, 'dean@example.test')
            )->token();
        }

        /**
         * The body exactly as ApprovalNoticeJob::deliver() builds a grouped
         * two-candidate mail: the digest-choice link first, then Approve/Reject/Edit
         * for the first candidate and the same three for the second.
         *
         * Keyed by candidate label so the two sets cannot be flattened into each
         * other -- merging them by purpose alone would leave a single token per
         * purpose, which is the condition that makes the older tests vacuous.
         *
         * @return array<string, array<string, string>> candidate label => purpose label => token
         */
        private function groupedMailOverTwoCandidates(): array
        {
            $purposes = [
                'approve' => ActionTokenPurpose::APPROVE_EVENT,
                'reject' => ActionTokenPurpose::REJECT_EVENT,
                'edit' => ActionTokenPurpose::EDIT,
            ];

            $mail = [];
            foreach (['first' => self::FIRST_CANDIDATE, 'second' => self::SECOND_CANDIDATE] as $label => $candidateId) {
                foreach ($purposes as $purposeLabel => $purpose) {
                    $mail[$label][$purposeLabel] = $this->issue($purpose, $candidateId);
                }
            }

            return $mail;
        }

        /**
         * The same tokens as one body, in the order deliver() writes them.
         *
         * @param array<string, array<string, string>> $mail
         * @return list<string>
         */
        private function bodyOf(array $mail): array
        {
            $body = [$this->issue(ActionTokenPurpose::CHANGE_NOTIFY_MODE)];
            foreach ($mail as $tokens) {
                foreach ($tokens as $secret) {
                    $body[] = $secret;
                }
            }

            return $body;
        }

        /**
         * The second failure that reached layer 3: a grouped mail carries one token
         * per purpose per candidate, so matching on purpose alone hands back
         * candidate one's edit link when the assertion is about candidate two.
         */
        public function testTheLookupReturnsTheTokenBoundToTheRequestedCandidate(): void
        {
            $mail = $this->groupedMailOverTwoCandidates();
            $body = $this->bodyOf($mail);

            self::assertCount(
                7,
                $body,
                'a grouped two-candidate mail carries one link per purpose per candidate, plus the digest choice'
            );
            self::assertNotSame(
                $mail['first']['approve'],
                $mail['second']['approve'],
                'each candidate needs its own approve token; one shared token cannot be tested against'
            );

            self::assertSame(
                $mail['second']['edit'],
                $this->linkFor($body, ActionTokenPurpose::EDIT, self::SECOND_CANDIDATE),
                'the edit link for the second candidate must not be the first candidate\'s'
            );
            self::assertSame(
                $mail['second']['reject'],
                $this->linkFor($body, ActionTokenPurpose::REJECT_EVENT, self::SECOND_CANDIDATE)
            );
            self::assertSame(
                $mail['first']['approve'],
                $this->linkFor($body, ActionTokenPurpose::APPROVE_EVENT, self::FIRST_CANDIDATE)
            );
            self::assertSame(
                $mail['second']['approve'],
                $this->linkFor($body, ActionTokenPurpose::APPROVE_EVENT, self::SECOND_CANDIDATE),
                'the same purpose against a different candidate id must return a different token'
            );
        }

        /**
         * The guard that would have caught it, stated as its own test so a vacuous
         * probe cannot later be mistaken for it.
         *
         * Every earlier test issued all of its tokens against one subject id, which
         * makes purpose alone sufficient to answer the question each one asks: such
         * a test passes unchanged against a purpose-only lookup and proves nothing
         * about candidate selection. This one fails if the subject id is dropped from
         * the key -- the lookup then returns candidate one's edit link.
         */
        public function testTheLookupCannotTellTwoCandidatesApart(): void
        {
            $mail = $this->groupedMailOverTwoCandidates();
            $body = $this->bodyOf($mail);

            // With purpose only the two candidates are indistinguishable: the loop
            // never looks at the subject id, so it always answers with the first
            // edit link in the body.
            $purposeOnly = $this->linkFor($body, ActionTokenPurpose::EDIT);
            self::assertSame(
                $mail['first']['edit'],
                $purposeOnly,
                'a purpose-only lookup returns the first edit link whatever candidate was meant'
            );
            self::assertNotSame(
                $mail['second']['edit'],
                $purposeOnly,
                'a purpose-only lookup cannot reach the second candidate at all'
            );

            // The subject id is what separates them, so it has to be asked for.
            self::assertSame(
                $mail['second']['edit'],
                $this->linkFor($body, ActionTokenPurpose::EDIT, self::SECOND_CANDIDATE)
            );
            self::assertSame(
                $mail['first']['edit'],
                $this->linkFor($body, ActionTokenPurpose::EDIT, self::FIRST_CANDIDATE)
            );
        }

        /**
         * The digest-choice link is bound to a wp user, not to a candidate
         * (ApprovalEditHandler mints CHANGE_NOTIFY_MODE against 'wp_user_id'), so
         * requiring a subject id must not be silently applied to it.
         */
        public function testTheDigestChoiceLinkIsNotBoundToACandidate(): void
        {
            $digestChoice = $this->tokens->issue(
                new ActionTokenBinding(
                    ActionTokenPurpose::CHANGE_NOTIFY_MODE,
                    'wp_user_id',
                    77,
                    'dean@example.test'
                )
            )->token();

            $body = [$digestChoice, $this->issue(ActionTokenPurpose::APPROVE_EVENT)];

            self::assertSame($digestChoice, $this->linkFor($body, ActionTokenPurpose::CHANGE_NOTIFY_MODE));
            self::assertSame(
                $digestChoice,
                $this->linkFor($body, ActionTokenPurpose::CHANGE_NOTIFY_MODE, 77),
                'its own subject id still matches'
            );
            self::assertNull(
                $this->linkFor($body, ActionTokenPurpose::CHANGE_NOTIFY_MODE, self::FIRST_CANDIDATE),
                'a candidate id must not match a link bound to a wp user'
            );
        }

        public function testReturnsNothingWhenTheCandidateIsAbsent(): void
        {
            $mail = $this->groupedMailOverTwoCandidates();

            self::assertNull(
                $this->linkFor($this->bodyOf($mail), ActionTokenPurpose::EDIT, 9999),
                'a token bound to a different candidate must not be borrowed'
            );
        }

        public function testFindsTheApprovalTokenEvenWhenAnotherLinkComesFirst(): void
    {
        // Body order exactly as ApprovalNoticeJob::deliver() builds it: the
        // digest-choice link is prepended, so index 0 is NOT the approval token.
        $links = [
            $this->issue(ActionTokenPurpose::CHANGE_NOTIFY_MODE),
            $this->issue(ActionTokenPurpose::APPROVE_EVENT),
            $this->issue(ActionTokenPurpose::REJECT_EVENT),
        ];

        self::assertSame($links[1], $this->linkFor($links, ActionTokenPurpose::APPROVE_EVENT));
        self::assertNotSame(
            $links[0],
            $this->linkFor($links, ActionTokenPurpose::APPROVE_EVENT),
            'the lookup must not fall back to the first token in the body'
        );
    }

    public function testResolvesTheSameTokenWhateverTheOrder(): void
    {
        $approve = $this->issue(ActionTokenPurpose::APPROVE_EVENT);
        $other = $this->issue(ActionTokenPurpose::CHANGE_NOTIFY_MODE);

        self::assertSame(
            $approve,
            $this->linkFor([$approve, $other], ActionTokenPurpose::APPROVE_EVENT)
        );
        self::assertSame(
            $approve,
            $this->linkFor([$other, $approve], ActionTokenPurpose::APPROVE_EVENT)
        );
    }

    public function testFindsEveryPurposeTheApprovalNoticeMints(): void
    {
            $minted = [
                ActionTokenPurpose::APPROVE_EVENT,
                ActionTokenPurpose::REJECT_EVENT,
                ActionTokenPurpose::EDIT,
                ActionTokenPurpose::CHANGE_NOTIFY_MODE,
            ];
            $links = [];
            foreach ($minted as $purpose) {
                $links[] = $this->issue($purpose);
            }

            foreach ($minted as $purpose) {
                $found = $this->linkFor($links, $purpose);
                self::assertNotNull($found, 'no link found for ' . $purpose->value);
                self::assertSame(
                    $purpose,
                    $this->tokens->inspect((string) $found)->binding?->purpose,
                    'a link resolved for ' . $purpose->value . ' must be that purpose'
                );
            }
        }

    public function testReturnsNothingWhenThePurposeIsAbsent(): void
    {
        $links = [$this->issue(ActionTokenPurpose::APPROVE_EVENT)];

        // This is the reviewer's mail: no assignment row, so no digest-choice
        // link. The integration check asserts the absence; this pins that the
        // lookup reports it rather than borrowing some other link.
        self::assertNull($this->linkFor($links, ActionTokenPurpose::CHANGE_NOTIFY_MODE));
    }

    public function testReturnsNothingForAnUnknownSecret(): void
    {
        self::assertNull($this->linkFor(['not-a-real-token-at-all-not-even-43-chars-long-x'],
            ActionTokenPurpose::APPROVE_EVENT));
    }

    public function testStillResolvesATokenThatHasBeenConsumed(): void
        {
            // The check presses the winning approval token twice, so its purpose
            // must still be readable after the first press has used it.
            $approve = $this->issue(ActionTokenPurpose::APPROVE_EVENT);
            $binding = new ActionTokenBinding(
                ActionTokenPurpose::APPROVE_EVENT,
                'event_candidate',
                4242,
                'dean@example.test'
            );
            self::assertSame(
                ActionTokenStatus::CONSUMED,
                $this->tokens->consume($approve, $binding)->status
            );
            self::assertSame(
                $approve,
                $this->linkFor([$approve], ActionTokenPurpose::APPROVE_EVENT)
            );
        }
}