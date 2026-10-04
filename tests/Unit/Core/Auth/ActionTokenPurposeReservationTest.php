<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Auth;

use ADCT\ParishIntake\Core\Approval\ApprovalRouteResolver;
use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
use ADCT\ParishIntake\Core\Events\EventValidator;
use ADCT\ParishIntake\Core\Ports\ApprovalRouteRepositoryInterface;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\MailerInterface;
use ADCT\ParishIntake\Core\Ports\OccurrenceMaintenanceInterface;
use ADCT\ParishIntake\Core\Ports\PublicationStoreInterface;
use ADCT\ParishIntake\Core\Publishing\CandidatePublisher;
use ADCT\ParishIntake\WordPress\Approval\ApprovalRecipients;
use ADCT\ParishIntake\WordPress\Auth\ApprovalDecisionHandler;
use ADCT\ParishIntake\WordPress\Auth\ApprovalEditHandler;
use ADCT\ParishIntake\WordPress\Auth\ConfirmationDecisionHandler;
use ADCT\ParishIntake\WordPress\Auth\LoginHandler;
use ADCT\ParishIntake\WordPress\Auth\NotifyModeChangeHandler;
use ADCT\ParishIntake\WordPress\Auth\RevertChangeHandler;
use ADCT\ParishIntake\WordPress\Auth\UnpublishEventHandler;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use ADCT\ParishIntake\WordPress\Database\Repository\DeaneryApproverRepository;
use ADCT\ParishIntake\WordPress\Events\EventListingGeneration;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Every ActionTokenPurpose is either handled or reserved, and this test is how
 * the next developer finds out which.
 *
 * #173 happened because three cases were neither: they minted tokens that no
 * page could act on, and the data model listed all eight as a flat set of
 * usable values, so nothing flagged them. A new case now fails here until it is
 * either wired to a handler or added to RESERVATIONS with its owning issue.
 */
final class ActionTokenPurposeReservationTest extends TestCase
{
    /**
     * Purposes with no handler yet, and the issue that owns each one.
     *
     * Adding a key here is a decision to wait, not a gap: the value must still
     * be a purpose on the enum, and the owning issue's work must be the one
     * that implements the handler. Drop the key when the handler is registered.
     *
     * @var array<string, int>
     */
    private const RESERVATIONS = [];

    public function testEveryPurposeIsEitherHandledOrReservedForAnIssue(): void
    {
        $handled = array_keys(self::handledPurposes());
        $orphans = [];
        $doubleBooked = [];

        foreach (ActionTokenPurpose::cases() as $purpose) {
            $isHandled = in_array($purpose->value, $handled, true);
            $isReserved = array_key_exists($purpose->value, self::RESERVATIONS);

            if ($isHandled && $isReserved) {
                $doubleBooked[] = $purpose->value;
            }

            if (! $isHandled && ! $isReserved) {
                $orphans[] = $purpose->value;
            }
        }

        self::assertSame(
            [],
            $doubleBooked,
            'These purposes are both handled and reserved, so the reservation no longer describes reality:'
                . ' remove each one from $RESERVATIONS.'
        );
        self::assertSame(
            [],
            $orphans,
            'These purposes have neither a handler nor a reservation, so their tokens are dead links.'
                . ' Register a handler in Plugin, or add the value to $RESERVATIONS with the owning issue.'
        );
    }

    /**
     * A reservation only means something while the enum still has the case.
     * Without this, deleting a case would leave a reservation behind and it
     * would quietly stop protecting anything.
     *
     * $RESERVATIONS is empty since #72, so the mismatches are collected and
     * asserted once rather than inside the loop: an empty loop would report
     * nothing and PHPUnit would flag the test as risky.
     */
    public function testEveryReservationNamesAPurposeThatStillExists(): void
    {
        $values = array_map(
            static fn (ActionTokenPurpose $purpose): string => $purpose->value,
            ActionTokenPurpose::cases()
        );

        $problems = [];

        foreach (self::RESERVATIONS as $value => $issue) {
            if (! in_array($value, $values, true)) {
                $problems[] = '"' . $value . '" is no longer an ActionTokenPurpose case.';
            }
            if (! is_int($issue) || $issue < 1) {
                $problems[] = 'The reservation for "' . $value . '" names no owning issue.';
            }
        }

        self::assertSame([], $problems);
    }

    /**
     * The enum and the data model are the two places a reader looks, so the
     * reservation, the case comment and the doc must all name the same issue.
     */
    public function testTheEnumCommentsAndTheDataModelNameTheReservedIssue(): void
    {
        $documentation = self::actionTokenTableSection();

        foreach (self::RESERVATIONS as $value => $issue) {
            // The comment sits above the case, so the assertion reads the
            // docblock and the declaration together rather than expecting the
            // issue number on one line.
            self::assertMatchesRegularExpression(
                '#/\*\*(?:(?!\*/).)*\#' . $issue . '(?:(?!\*/).)*\*/\s*case\s+'
                    . preg_quote(self::caseName($value), '#') . '\b#s',
                self::read('src/Core/Auth/ActionTokenPurpose.php'),
                'The comment on ActionTokenPurpose::' . self::caseName($value) . ' must name issue #' . $issue
                    . ', the same issue this test reserves "' . $value . '" for.'
            );
            self::assertMatchesRegularExpression(
                '/`' . preg_quote($value, '/') . '`[^.]*#' . $issue . '/',
                $documentation,
                'docs/data-model.md must record which issue owns the reserved "' . $value . '" purpose.'
            );
        }
    }

    /**
     * The data model is the human-readable copy of the enum, and #173's real
     * cost was those two disagreeing, so every value must still be listed and
     * every reserved one marked with its owner.
     */
    public function testTheDataModelListsEveryPurpose(): void
    {
        $documentation = self::actionTokenTableSection();

        foreach (ActionTokenPurpose::cases() as $purpose) {
            self::assertStringContainsString(
                '`' . $purpose->value . '`',
                $documentation,
                'docs/data-model.md must list the "' . $purpose->value . '" purpose for the action token table.'
            );
        }
    }

    /**
     * handledPurposes() mirrors the registration block in Plugin's constructor,
     * and Plugin cannot be booted in a unit test because its constructor builds
     * WordPress adapters. So the block is read as source and compared, which is
     * what stops the mirror from drifting.
     *
     * No setAccessible() call: it has been a no-op since PHP 8.1 and is
     * deprecated in 8.5.
     */
    public function testPluginRegistersExactlyTheHandlersThisTestTreatsAsHandled(): void
    {
        $source = self::read('src/WordPress/Plugin.php');
        $start = strpos($source, '$this->actionTokenHandlers = new ActionTokenHandlerRegistry');
        $end = $start === false ? false : strpos($source, '$this->actionTokenEndpointDependencies', $start);

        self::assertIsInt($start, 'The handler registration block was not found in Plugin.php.');
        self::assertIsInt($end, 'The end of the handler registration block was not found in Plugin.php.');

        preg_match_all('/ActionTokenPurpose::([A-Z_]+)/', substr($source, $start, $end - $start), $matches);

        $registered = [];
        foreach ($matches[1] as $case) {
            self::assertTrue(
                defined(ActionTokenPurpose::class . '::' . $case),
                'Plugin references ActionTokenPurpose::' . $case . ', which is not a case on the enum.'
            );
            $registered[] = constant(ActionTokenPurpose::class . '::' . $case)->value;
        }

        // ApprovalEditHandler, RevertChangeHandler, UnpublishEventHandler and
                // NotifyModeChangeHandler are registered for their own fixed purposes, so
                // those purposes never appear as a name in the registration block.
        $registered[] = self::editHandler()->purpose()->value;
        $registered[] = self::revertHandler()->purpose()->value;
                $registered[] = self::unpublishHandler()->purpose()->value;
                $registered[] = self::notifyModeHandler()->purpose()->value;

        $registered = array_values(array_unique($registered));
        $expected = array_keys(self::handledPurposes());
        sort($registered);
        sort($expected);

        self::assertSame(
            $expected,
            $registered,
            'Plugin no longer registers the handlers this test treats as handled, or registers another.'
                . ' Update this test, the enum comments and docs/data-model.md together.'
        );
    }

    /**
     * ADR 0007 specifies a 30-minute magic link, and the login purpose is that
     * magic link, so the shorter window has to survive #72 rather than quietly
     * inheriting the 14-day event default.
     *
     * ActionTokenServiceTest covers the lifetime as minted; this covers it as
     * declared, so a purpose cannot be left without a bound at all.
     */
    public function testTheLoginPurposeKeepsTheMagicLinkLifetime(): void
    {
        $login = self::loginHandler();

        self::assertSame(
            ActionTokenPurpose::LOGIN,
            $login->purpose(),
            '#72 consumes "login": it is handled by LoginHandler, so it is no longer a reservation.'
        );

        foreach (self::handledPurposes() as $value => $lifetime) {
            self::assertGreaterThan(
                0,
                $lifetime,
                'The "' . $value . '" purpose must still expire, or its tokens would sit in the table forever.'
            );
        }

        self::assertSame(30 * 60, ActionTokenPurpose::LOGIN->defaultLifetimeSeconds());
    }

    /**
     * The purposes Plugin registers a handler for, by building each handler the
     * way the constructor does.
     *
     * Building the real handlers rather than listing purpose names is what makes
     * this meaningful: a handler that stops being constructible, or that stops
     * declaring the purpose it is registered for, fails here.
     *
     * @return array<string, int> purpose value => default lifetime seconds
     */
    private static function handledPurposes(): array
    {
        $clock = self::createStub(ClockInterface::class);
        $database = self::createStub(DatabaseConnectionInterface::class);
        $routes = new ApprovalRouteResolver(self::createStub(ApprovalRouteRepositoryInterface::class));
        $recipients = new ApprovalRecipients($routes);
        $publisher = new CandidatePublisher(
            self::createStub(PublicationStoreInterface::class),
            new EventValidator(new DateTimeZone('Africa/Johannesburg'))
        );

        $handlers = [];
        foreach ([ActionTokenPurpose::CONFIRM, ActionTokenPurpose::DENY] as $purpose) {
            $handler = new ConfirmationDecisionHandler($purpose, $database, $routes, $publisher, $clock);
            $handlers[$handler->purpose()->value] = $purpose->defaultLifetimeSeconds();
        }
        foreach ([ActionTokenPurpose::APPROVE_EVENT, ActionTokenPurpose::REJECT_EVENT] as $purpose) {
            $handler = new ApprovalDecisionHandler(
                $purpose,
                $database,
                $recipients,
                $publisher,
                self::createStub(MailerInterface::class),
                $clock
            );
            $handlers[$handler->purpose()->value] = $purpose->defaultLifetimeSeconds();
        }
        $edit = self::editHandler();
        $handlers[$edit->purpose()->value] = $edit->purpose()->defaultLifetimeSeconds();
        $revert = self::revertHandler();
        $handlers[$revert->purpose()->value] = $revert->purpose()->defaultLifetimeSeconds();
                $unpublish = self::unpublishHandler();
                $handlers[$unpublish->purpose()->value] = $unpublish->purpose()->defaultLifetimeSeconds();
        $login = self::loginHandler();
        $handlers[$login->purpose()->value] = $login->purpose()->defaultLifetimeSeconds();
        $notifyMode = self::notifyModeHandler();
        $handlers[$notifyMode->purpose()->value] = $notifyMode->purpose()->defaultLifetimeSeconds();

        return $handlers;
    }

    /**
     * Built the way Plugin builds it: the purpose is the only thing a login
     * handler is told, so that is all this can assert.
     */
    private static function loginHandler(): LoginHandler
    {
        return new LoginHandler(ActionTokenPurpose::LOGIN);
    }

    private static function revertHandler(): RevertChangeHandler
    {
        $clock = self::createStub(ClockInterface::class);

        return new RevertChangeHandler(
            self::createStub(DatabaseConnectionInterface::class),
            new ApprovalRecipients(
                new ApprovalRouteResolver(self::createStub(ApprovalRouteRepositoryInterface::class))
            ),
            self::createStub(MailerInterface::class),
            $clock,
            self::createStub(OccurrenceMaintenanceInterface::class),
            new EventListingGeneration(),
            new DateTimeZone('Africa/Johannesburg')
        );
    }

        /**
         * #71: the other link ADR 0008 point 4 puts in the change notice. Built the
         * way Plugin builds it, so a change to its constructor or to the purpose it
         * declares fails here as well as in UnpublishEventHandlerTest.
         */
        private static function unpublishHandler(): UnpublishEventHandler
        {
            $clock = self::createStub(ClockInterface::class);

            return new UnpublishEventHandler(
                self::createStub(DatabaseConnectionInterface::class),
                new ApprovalRecipients(
                    new ApprovalRouteResolver(self::createStub(ApprovalRouteRepositoryInterface::class))
                ),
                self::createStub(MailerInterface::class),
                $clock,
                self::createStub(OccurrenceMaintenanceInterface::class),
                new EventListingGeneration(),
                new DateTimeZone('Africa/Johannesburg')
            );
        }

    private static function editHandler(): ApprovalEditHandler
    {
        return new ApprovalEditHandler(
            self::createStub(DatabaseConnectionInterface::class),
            new ApprovalRecipients(
                new ApprovalRouteResolver(self::createStub(ApprovalRouteRepositoryInterface::class))
            ),
            self::createStub(ClockInterface::class)
        );
    }

    /**
     * #169: the handler behind the digest-choice link in the approval email.
     *
     * Built the way Plugin builds it, so a change to its constructor or to the
     * purpose it declares fails here as well as in NotifyModeChangeHandlerTest.
     */
    private static function notifyModeHandler(): NotifyModeChangeHandler
    {
        $database = self::createStub(DatabaseConnectionInterface::class);

        return new NotifyModeChangeHandler(
            $database,
            new DeaneryApproverRepository($database),
            self::createStub(ClockInterface::class)
        );
    }

    private static function caseName(string $value): string
    {
        foreach (ActionTokenPurpose::cases() as $purpose) {
            if ($purpose->value === $value) {
                return $purpose->name;
            }
        }

        self::fail('No ActionTokenPurpose case has the value "' . $value . '".');
    }

    /**
     * Only the adct_pi_action_tokens section, so an assertion cannot be
     * satisfied by a mention in a different table's description.
     */
    private static function actionTokenTableSection(): string
    {
        $documentation = self::read('docs/data-model.md');
        $start = strpos($documentation, '### `adct_pi_action_tokens`');

        self::assertIsInt($start, 'The adct_pi_action_tokens section was not found in docs/data-model.md.');

        $end = strpos($documentation, '### `adct_pi_action_token_rate_limits`', $start);

        return substr($documentation, $start, ($end === false ? strlen($documentation) : $end) - $start);
    }

    private static function read(string $relativePath): string
    {
        $path = dirname(__DIR__, 4) . '/' . $relativePath;
        $contents = is_file($path) ? file_get_contents($path) : false;

        self::assertIsString($contents, $relativePath . ' could not be read.');

        return $contents;
    }
}
