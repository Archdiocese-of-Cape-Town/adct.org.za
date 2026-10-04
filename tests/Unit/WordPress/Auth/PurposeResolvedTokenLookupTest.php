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
 * The shape it guards against is real: ApprovalNoticeJob::deliver() prepends the
 * digest-choice link (issue #169) ahead of the Approve/Reject/Edit links, so the
 * approve token stopped being the first token in the body.
 */
final class PurposeResolvedTokenLookupTest extends TestCase
{
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
     * @param list<string> $links
     */
    private function linkFor(array $links, ActionTokenPurpose $want): ?string
    {
        foreach ($links as $link) {
            $binding = $this->tokens->inspect((string) $link)->binding;
            if ($binding !== null && $binding->purpose === $want) {
                return (string) $link;
            }
        }

        return null;
    }

    private function issue(ActionTokenPurpose $purpose): string
    {
        return $this->tokens->issue(
            new ActionTokenBinding($purpose, 'event_candidate', 4242, 'dean@example.test')
        )->token();
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