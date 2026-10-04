<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Admin;

use ADCT\ParishIntake\Core\Audit\AuditQuery;
use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
use ADCT\ParishIntake\Core\Auth\ActionTokenRateLimiter;
use ADCT\ParishIntake\Core\Auth\Capabilities;
use ADCT\ParishIntake\Core\Events\NearMePoint;
use ADCT\ParishIntake\Core\Ingestion\AttachmentStoragePolicy;
use ADCT\ParishIntake\Core\Jobs\JobRunner;
use ADCT\ParishIntake\Core\Jobs\MailboxPollingJob;
use ADCT\ParishIntake\Core\Mail\MailQueueConfiguration;
use ADCT\ParishIntake\Core\Ocr\OcrExtractionLimits;
use ADCT\ParishIntake\Core\Ocr\OcrTextEnrichmentService;
use ADCT\ParishIntake\Core\Pdf\PdfExtractionLimits;
use ADCT\ParishIntake\Core\Pdf\PdfTextEnrichmentService;
use ADCT\ParishIntake\Core\Sources\Source;
use ADCT\ParishIntake\Core\Sources\SourceHealthRecorder;
use ADCT\ParishIntake\WordPress\Approval\ApprovalNoticeJob;
use ADCT\ParishIntake\WordPress\Approval\FrontEndApprovalQueue;
use ADCT\ParishIntake\WordPress\Auth\NotifyModeChangeHandler;
use ADCT\ParishIntake\WordPress\Jobs\HealthAlerts;
use ADCT\ParishIntake\WordPress\Jobs\OcrSettings;
use ADCT\ParishIntake\WordPress\Plugin;
use PHPUnit\Framework\TestCase;

/**
 * The numbers the guides state, checked against the code they describe.
 *
 * A guide is prose, so nothing else notices when it starts lying: a wrong cap
 * or a wrong lifetime looks exactly like a right one on the page, and the
 * person who finds out is the parish secretary who trusted it. Every figure
 * below is therefore asserted twice — once against the constant in the source,
 * and once against the sentence in the guide that a reader would actually read.
 *
 * Only public constants are used. A private constant cannot be read from a test,
 * so anything private is left out rather than asserted against a copy of its
 * value, which would only prove the test agrees with itself.
 *
 * If one of these fails, the guide is wrong until someone proves otherwise: fix
 * the guide, not the assertion, unless the code really did change behaviour.
 */
final class AdminGuideClaimsTest extends TestCase
{
    private const OPERATOR_GUIDE = 'docs/operator-guide.md';
    private const APPROVER_GUIDE = 'docs/approver-guide.md';
    private const SUBMISSION_GUIDE = 'docs/parish-submission-guide.md';

    /**
    * @var list<string>
    */
    private const GUIDES = [
    self::OPERATOR_GUIDE,
    self::APPROVER_GUIDE,
    self::SUBMISSION_GUIDE,
    ];

    /**
    * The link lifetimes, in seconds.
    *
    * A class constant may not call a method, so these are the seconds the
    * guide converts the enum's own figures into. `linkLifetimesAgree()` is
    * what ties them to ActionTokenPurpose; the seconds are kept here rather
    * than derived at run time so the figure table can still be a constant.
    */
    private const LOGIN_LINK_SECONDS = 30 * 60;
    private const EDIT_LINK_SECONDS = 14 * 24 * 60 * 60;
    private const NOTIFY_MODE_LINK_SECONDS = 7 * 24 * 60 * 60;

    /**
    * Mail: the shared hosting allowance, and the plugin's share of it.
    *
    * @var list<array{0: string, 1: int|float, 2: string, 3: string, 4: string}>
    */
    private const MAIL_FIGURES = [
    [
    'default hourly cap',
    MailQueueConfiguration::DEFAULT_HOURLY_CAP,
    self::OPERATOR_GUIDE,
    'The default is **100 per hour**',
    '100',
    ],
    [
    'maximum hourly cap',
    MailQueueConfiguration::MAX_HOURLY_CAP,
    self::OPERATOR_GUIDE,
    'an integer from `1` to `500`',
    '500',
    ],
    [
    'terminal attempts',
    MailQueueConfiguration::MAX_ATTEMPTS,
    self::OPERATOR_GUIDE,
    'terminally failed after five attempts',
    '5',
    ],
    ];

    /**
    * Jobs: the 90-second host limit forces a budget well under it.
    *
    * @var list<array{0: string, 1: int|float, 2: string, 3: string, 4: string}>
    */
    private const JOB_FIGURES = [
    [
    'job time budget',
    JobRunner::DEFAULT_TIME_BUDGET_SECONDS,
    self::OPERATOR_GUIDE,
    'stops at 60 seconds or 100 mailbox steps',
    '60',
    ],
    [
    'job item budget',
    JobRunner::DEFAULT_ITEM_BUDGET,
    self::OPERATOR_GUIDE,
    'stops at 60 seconds or 100 mailbox steps',
    '100',
    ],
    ];

    /**
    * Health: when to warn, and how often the same warning may repeat.
    *
    * @var list<array{0: string, 1: int|float, 2: string, 3: string, 4: string}>
    */
    private const HEALTH_FIGURES = [
    [
    'failure threshold',
    HealthAlerts::FAILURE_THRESHOLD,
    self::OPERATOR_GUIDE,
    'After **3 consecutive failures** of a source or job',
    '3',
    ],
    ];

    /**
    * Sources: how often we read them, and when we give up on one.
    *
    * @var list<array{0: string, 1: int|float, 2: string, 3: string, 4: string}>
    */
    private const SOURCE_FIGURES = [
    [
    'default poll interval',
    Source::DEFAULT_POLL_INTERVAL_MINUTES,
    self::OPERATOR_GUIDE,
    'default to 1,440 minutes (24 hours)',
    '1440',
    ],
    [
    'minimum poll interval',
    Source::MINIMUM_POLL_INTERVAL_MINUTES,
    self::OPERATOR_GUIDE,
    'cannot be set below 10 minutes',
    '10',
    ],
    [
    'unreliable threshold',
    SourceHealthRecorder::UNRELIABLE_FAILURE_THRESHOLD,
    self::OPERATOR_GUIDE,
    'after five consecutive failures',
    '5',
    ],
    [
    'retry backoff base',
    MailboxPollingJob::FAILURE_RETRY_BASE_MINUTES,
    self::OPERATOR_GUIDE,
    'backoff starts at 10 minutes',
    '10',
    ],
    [
    'retry backoff cap',
    MailboxPollingJob::FAILURE_RETRY_MAX_MINUTES,
    self::OPERATOR_GUIDE,
    'capped at 6 hours',
    '360',
    ],
    ];

    /**
    * Approver notices: when the digest goes out, and how many events one
    * per-item email may carry.
    *
    * @var list<array{0: string, 1: int|float, 2: string, 3: string, 4: string}>
    */
    private const NOTICE_FIGURES = [
    [
    'digest hour',
    ApprovalNoticeJob::DEFAULT_DIGEST_HOUR,
    self::OPERATOR_GUIDE,
    '07:00',
    '7',
    ],
    [
    'events per approval email',
    20,
    self::OPERATOR_GUIDE,
    'at most one grouped email of up to 20 events per run',
    '20',
    ],
    ];

    /**
    * Sign-in and the approval links: the lifetimes a person is told about.
    *
    * @var list<array{0: string, 1: int|float, 2: string, 3: string, 4: string}>
    */
    private const AUTH_FIGURES = [
    [
    'sign-in link lifetime',
    self::LOGIN_LINK_SECONDS,
    self::OPERATOR_GUIDE,
    'expires after **30 minutes**',
    '1800',
    ],
    [
    'approval link lifetime',
    self::EDIT_LINK_SECONDS,
    self::OPERATOR_GUIDE,
    'Links expire after 14 days',
    '1209600',
    ],
    [
    'email frequency link lifetime',
    self::NOTIFY_MODE_LINK_SECONDS,
    self::OPERATOR_GUIDE,
    'expires after **7 days**',
    '604800',
    ],
    [
    'signed-in duration',
    Plugin::DEFAULT_AUTH_COOKIE_LIFETIME_DAYS,
    self::OPERATOR_GUIDE,
    'last **365 days**',
    '365',
    ],
    ];

    /**
    * Reading a notice's attachments: the ceilings that keep one bulletin from
    * holding up the processing job.
    *
    * @var list<array{0: string, 1: int|float, 2: string, 3: string, 4: string}>
    */
    private const EXTRACTION_FIGURES = [
    [
    'attachment size ceiling',
    AttachmentStoragePolicy::MAX_ATTACHMENT_SIZE_BYTES / (1024 * 1024),
    self::OPERATOR_GUIDE,
    'no larger than 15 MiB',
    '15',
    ],
    [
    'PDF page ceiling',
    PdfExtractionLimits::DEFAULT_MAX_PAGES,
    self::OPERATOR_GUIDE,
    'It stops at 15 MB, 10 pages or 10 seconds',
    '10',
    ],
    [
    'PDF time budget',
    PdfExtractionLimits::DEFAULT_TIME_BUDGET_SECONDS,
    self::OPERATOR_GUIDE,
    'It stops at 15 MB, 10 pages or 10 seconds',
    '10',
    ],
    [
    'PDFs per message',
    PdfTextEnrichmentService::MAX_ATTACHMENTS_PER_MESSAGE,
    self::OPERATOR_GUIDE,
    'reads at most 5 PDFs per message',
    '5',
    ],
    [
    'shared PDF message budget',
    PdfExtractionLimits::DEFAULT_MESSAGE_TIME_BUDGET_SECONDS,
    self::OPERATOR_GUIDE,
    "One email's PDFs also share a 30 second budget",
    '30',
    ],
    [
    'posters per message',
    OcrTextEnrichmentService::MAX_IMAGES_PER_MESSAGE,
    self::OPERATOR_GUIDE,
    'At most 3 posters per email are sent',
    '3',
    ],
    [
    'shared OCR message budget',
    OcrExtractionLimits::DEFAULT_MESSAGE_TIME_BUDGET_SECONDS,
    self::OPERATOR_GUIDE,
    'the whole message shares a 30-second budget',
    '30',
    ],
    [
    'daily poster OCR limit',
    OcrSettings::DEFAULT_DAILY_CALL_LIMIT,
    self::OPERATOR_GUIDE,
    'how many posters a day may be read, 20 by default',
    '20',
    ],
    ];

    /**
    * Every stated figure is the figure the code uses.
    *
    * Read as a loop rather than one method per number so that adding a figure
    * is a single entry, and so a wrong figure is named in the failure message
    * instead of arriving as a bare "expected 100, got 99".
    */
    public function testEveryFigureTheGuidesStateIsTheFigureTheCodeUses(): void
    {
    $figures = array_merge(
    self::MAIL_FIGURES,
    self::JOB_FIGURES,
    self::HEALTH_FIGURES,
    self::SOURCE_FIGURES,
    self::NOTICE_FIGURES,
    self::AUTH_FIGURES,
    self::EXTRACTION_FIGURES
    );

    self::assertNotEmpty($figures, 'The figure table is empty, so this test asserts nothing.');

    foreach ($figures as [$label, $expected, $guidePath, $sentence, $numberInTheGuide]) {
    self::assertStringContainsString(
    $sentence,
    self::guide($guidePath),
    $label . ': ' . $guidePath . ' no longer states "' . $sentence . '".'
    );

    self::assertSame(
    $expected,
    self::numberIn($numberInTheGuide),
    $label . ': ' . $guidePath . ' states "' . $numberInTheGuide . '" in the sentence "'
    . $sentence . '", but the code does not use that value.'
    );
    }
    }

    /**
    * Two figures are stated as a range in one sentence — the mail cap is
    * written "from 1 to 500" — so each end is checked against its own
    * constant, and the range is read back out of the guide's own sentence so
    * a change to either end is caught.
    */
    public function testTheRangeFiguresMatchBothOfTheirEnds(): void
    {
    foreach (self::rangeFigures() as $label => [$sentence, $floor, $ceiling]) {
    $guide = self::guide(self::OPERATOR_GUIDE);

    self::assertStringContainsString(
    $sentence,
    $guide,
    $label . ': the guide no longer states "' . $sentence . '".'
    );

    // Read the range back out of the guide rather than off the
    // sentence above: if the sentence changed, this finds the new
    // numbers and compares those.
    $asWritten = self::rangeIn($sentence);

    self::assertNotNull(
    $asWritten,
    $label . ': the guide sentence is no longer a "from x to y" range: ' . $sentence
    );

    self::assertSame(
    [$floor, $ceiling],
    $asWritten,
    $label . ': the guide states "' . $asWritten[0] . '" to "' . $asWritten[1]
    . '" in "' . $sentence . '", but the code uses ' . $floor . ' to ' . $ceiling . '.'
    );
    }
    }

    /**
    * @return iterable<string, array{0: string, 1: int, 2: int}>
    */
    private static function rangeFigures(): iterable
    {
    yield 'mail hourly cap' => [
    'an integer from `1` to `500`',
    1,
    MailQueueConfiguration::MAX_HOURLY_CAP,
    ];
    }

    /**
    * The poster limit's floor and ceiling are not in the guide but on the
    * Settings screen, as the input's own min and max. A limit the screen
    * refuses to accept but the guide implies would cost a parish its poster
    * text, so the two are checked against each other.
    */
    public function testThePosterLimitIsBoundedWhereTheScreenSaysItIsBounded(): void
    {
    $screen = self::methodBody(
    'src/WordPress/Admin/ParserPage.php',
    'public function renderSettingsPage()'
    );

    self::assertStringContainsString(
    'min="<?php echo esc_attr((string) OcrSettings::MIN_DAILY_CALL_LIMIT); ?>"',
    $screen,
    'The Settings screen no longer states the smallest daily poster limit the plugin accepts.'
    );
    self::assertStringContainsString(
    'max="<?php echo esc_attr((string) OcrSettings::MAX_DAILY_CALL_LIMIT); ?>"',
    $screen,
    'The Settings screen no longer states the largest daily poster limit the plugin accepts.'
    );

    // The guide quotes the default, and the default has to be a value the
    // input will actually accept.
    self::assertGreaterThanOrEqual(
    OcrSettings::MIN_DAILY_CALL_LIMIT,
    OcrSettings::DEFAULT_DAILY_CALL_LIMIT,
    'The default daily poster limit is below the floor the Settings screen allows.'
    );
    self::assertLessThanOrEqual(
    OcrSettings::MAX_DAILY_CALL_LIMIT,
    OcrSettings::DEFAULT_DAILY_CALL_LIMIT,
    'The default daily poster limit is above the ceiling the Settings screen allows.'
    );
    }

    /**
    * The three lifetimes a guide quotes are each a constant above plus a unit
    * the guide chose. This is the assertion that keeps the two halves honest:
    * if ActionTokenPurpose changes a default, the figure table stops matching.
    */
    public function testLinkLifetimesAgree(): void
    {
    self::assertSame(
    self::LOGIN_LINK_SECONDS,
    ActionTokenPurpose::LOGIN->defaultLifetimeSeconds(),
    'The sign-in link lifetime in the guide is no longer the one ActionTokenPurpose hands out.'
    );
    self::assertSame(
    self::EDIT_LINK_SECONDS,
    ActionTokenPurpose::EDIT->defaultLifetimeSeconds(),
    'The approval link lifetime in the guide is no longer the one ActionTokenPurpose hands out.'
    );
    self::assertSame(
    self::NOTIFY_MODE_LINK_SECONDS,
    ActionTokenPurpose::CHANGE_NOTIFY_MODE->defaultLifetimeSeconds(),
    'The email-frequency link lifetime in the guide is no longer the one ActionTokenPurpose hands out.'
    );
    }

    /**
    * A lifetime that grows past a year stops being a link and starts being a
    * permanent capability, so every one of them stays short.
    */
    public function testNoActionLinkLastsLongerThanThirtyDays(): void
    {
    foreach ([ActionTokenPurpose::LOGIN, ActionTokenPurpose::EDIT, ActionTokenPurpose::CHANGE_NOTIFY_MODE] as $purpose) {
    self::assertLessThanOrEqual(
    30 * 24 * 60 * 60,
    $purpose->defaultLifetimeSeconds(),
    $purpose->name . ' now outlives 30 days, which the guides do not say.'
    );
    }
    }

    /**
    * The sign-in link is the shortest of the three, because it is the only one
    * a message scanner could follow without a human present.
    */
    public function testTheSignInLinkIsTheShortestLived(): void
    {
    self::assertLessThan(
    ActionTokenPurpose::EDIT->defaultLifetimeSeconds(),
    ActionTokenPurpose::LOGIN->defaultLifetimeSeconds()
    );
    }

    /**
    * Two figures are spelled out in words, so the words themselves are checked
    * against the constant. A guide that says "four attempts" beside a code that
    * gives up after five is exactly the sort of error a reader cannot see.
    */
    public function testTheFiguresSpelledInWordsAreTheOnesTheCodeUses(): void
    {
    $guide = self::guide(self::OPERATOR_GUIDE);

    foreach ([
    ['terminally failed after five attempts', MailQueueConfiguration::MAX_ATTEMPTS, 'terminal send attempts'],
    ['after five consecutive failures', SourceHealthRecorder::UNRELIABLE_FAILURE_THRESHOLD, 'unreliable-source failures'],
    ] as [$sentence, $actual, $label]) {
    self::assertStringContainsString($sentence, $guide, $guide . ' no longer states "' . $sentence . '".');

    $word = self::spelledNumberIn($sentence);

    self::assertNotNull(
    $word,
    'The guide sentence no longer spells a number in words: ' . $sentence
    );
    self::assertSame(
    $actual,
    $word,
    'The guide says "' . $sentence . '", which is ' . $word . ' attempts/failures, but the code uses '
    . $actual . ' for ' . $label . '.'
    );
    }
    }

    /**
    * The stall warning is stated in words, so the words are checked against
    * the seconds rather than merely required to be present.
    *
    * 8,100 seconds is 2 hours 15 minutes. If the threshold moved to, say,
    * 2 hours 30 minutes, the sentence would still contain "2 hours" and this
    * is the only assertion that notices.
    */
    public function testTheStallThresholdIsQuotedInTheRightUnit(): void
    {
    self::assertSame(
    '2 hours 15 minutes',
    self::asHoursAndMinutes(HealthAlerts::STALL_SECONDS),
    'HealthAlerts::STALL_SECONDS no longer works out to the time the operator guide quotes.'
    );
    self::assertStringContainsString(
    '**more than 2 hours 15 minutes**',
    self::guide(self::OPERATOR_GUIDE)
    );
    }

    /**
    * 360 minutes is quoted as "6 hours" and 10 minutes as "10 minutes", so the
    * guide's own phrase is converted back to minutes and compared with the
    * constant. Writing "8 hours" beside a six-hour cap would otherwise pass
    * unnoticed, because both the old and the new value contain a number.
    */
    public function testTheRetryBackoffIsQuotedInTheUnitsTheGuideUses(): void
    {
    foreach ([
    ['backoff starts at 10 minutes', MailboxPollingJob::FAILURE_RETRY_BASE_MINUTES, 'retry backoff start'],
    ['capped at 6 hours', MailboxPollingJob::FAILURE_RETRY_MAX_MINUTES, 'retry backoff ceiling'],
    ] as [$sentence, $actual, $label]) {
    $guide = self::guide(self::OPERATOR_GUIDE);

    self::assertStringContainsString($sentence, $guide, $guide . ' no longer states "' . $sentence . '".');

    $minutes = self::asMinutes($sentence);

    self::assertNotNull($minutes, 'The guide sentence no longer states a duration: ' . $sentence);
    self::assertSame(
    $actual,
    $minutes,
    'The guide quotes the ' . $label . ' as "' . $sentence . '" (' . $minutes
    . ' minutes), but the code uses ' . $actual . ' minutes.'
    );
    }
    }

    /**
    * The retry cap must exceed the base, or the doubling backoff described in
    * the guide would stop before it had doubled even once.
    */
    public function testTheRetryBackoffCanActuallyBackOff(): void
    {
    self::assertGreaterThan(
    MailboxPollingJob::FAILURE_RETRY_BASE_MINUTES,
    MailboxPollingJob::FAILURE_RETRY_MAX_MINUTES,
    'The retry ceiling is not above the retry start, so the backoff never doubles.'
    );
    }

    /**
    * 1,440 minutes is quoted as "1,440 minutes (24 hours)". Both units are read
    * back out of that one sentence and checked, so the guide cannot offer the
    * right number in minutes and the wrong one in hours.
    */
    public function testTheDefaultPollIntervalIsQuotedInBothUnits(): void
    {
    $sentence = 'default to 1,440 minutes (24 hours)';
    $guide = self::guide(self::OPERATOR_GUIDE);

    self::assertStringContainsString($sentence, $guide, $guide . ' no longer states the default poll interval.');

    self::assertSame(
    [Source::DEFAULT_POLL_INTERVAL_MINUTES, (int) (Source::DEFAULT_POLL_INTERVAL_MINUTES / 60)],
    self::minutesAndHoursIn($sentence),
    'The guide states the default poll interval as "' . $sentence . '", but the code uses '
    . Source::DEFAULT_POLL_INTERVAL_MINUTES . ' minutes ('
    . (Source::DEFAULT_POLL_INTERVAL_MINUTES / 60) . ' hours).'
    );
    }

    /**
    * The digest hour is a local hour and the guide quotes a clock time, so the
    * zero padding is part of the claim: 7 must read as 07:00, not 7:00.
    */
    public function testTheDigestHourIsQuotedAsAClockTime(): void
    {
    $guide = self::guide(self::OPERATOR_GUIDE);

    self::assertStringContainsString('07:00', $guide, $guide . ' no longer states the digest time.');

    // The guide's own clock time is parsed back to an hour and compared
    // with the constant, and the zero padding is checked separately,
    // because "7:00" is not the same claim as "07:00".
    $asWritten = self::clockTimeIn('07:00');

    self::assertNotNull($asWritten, 'The guide sentence no longer states a clock time.');
    self::assertSame(
    ApprovalNoticeJob::DEFAULT_DIGEST_HOUR,
    $asWritten,
    'The guide quotes the digest as "07:00" but the code sends it at ' . $asWritten . ':00.'
    );
    self::assertSame(
    self::asClockTime($asWritten),
    '07:00',
    'The guide writes the digest hour without its leading zero, which reads as a different time of day.'
    );
    }

    /**
    * A digest before anybody is awake would be worse than a digest late in the
    * day, so the hour is a real morning hour rather than midnight.
    */
    public function testTheDigestGoesOutInTheMorning(): void
    {
    self::assertGreaterThanOrEqual(6, ApprovalNoticeJob::DEFAULT_DIGEST_HOUR);
    self::assertLessThan(12, ApprovalNoticeJob::DEFAULT_DIGEST_HOUR);
    }

    /**
    * The "Within" dropdown is the ladder of radii in NearMePoint, and the guide
    * lists that same ladder. A radius added to or removed from the code without
    * a guide change would leave a visitor offered a choice the guide never
    * mentions — or worse, a guide that describes a dropdown that no longer
    * offers what it says.
    */
    public function testTheNearMeRadiiInTheGuideAreTheLadderInTheCode(): void
    {
    $sentence = 'to 5, 10, 25, 50 or 100 km';
    $guide = self::guide(self::OPERATOR_GUIDE);

    self::assertStringContainsString($sentence, $guide, $guide . ' no longer lists the distance choices.');

    $asWritten = self::kmListIn($sentence);

    self::assertNotNull($asWritten, 'The guide sentence no longer lists distances: ' . $sentence);
    self::assertSame(
    NearMePoint::RADIUS_CHOICES_KM,
    $asWritten,
    'The guide offers a visitor ' . implode(', ', $asWritten) . ' km, but the dropdown offers '
    . implode(', ', NearMePoint::RADIUS_CHOICES_KM) . ' km.'
    );
    }

    /**
    * The dropdown's default has to be one of its own choices, or a visitor who
    * never touches the control is filtering by something the control cannot
    * represent.
    */
    public function testTheDefaultNearMeRadiusIsOneOfTheOfferedChoices(): void
    {
    self::assertContains(
    NearMePoint::DEFAULT_RADIUS_KM,
    NearMePoint::RADIUS_CHOICES_KM,
    'NearMePoint now defaults to a radius its own dropdown does not offer.'
    );

    // And it is one of the radii the guide lists, not a hidden extra one.
    $sentence = 'to 5, 10, 25, 50 or 100 km';

    self::assertContains(
    NearMePoint::DEFAULT_RADIUS_KM,
    (array) self::kmListIn($sentence),
    'The guide does not mention the default distance of '
    . NearMePoint::DEFAULT_RADIUS_KM . ' km.'
    );
    }

    /**
    * The radius ladder must stay ascending and free of duplicates, or the
    * select shows the same choice twice and the default is not the middle of
    * anything.
    */
    public function testTheRadiusLadderIsAscendingAndUnique(): void
    {
    $sorted = NearMePoint::RADIUS_CHOICES_KM;
    sort($sorted);

    self::assertSame(
    NearMePoint::RADIUS_CHOICES_KM,
    $sorted,
    'The radius choices are no longer in ascending order.'
    );
    self::assertSame(
    array_values(array_unique(NearMePoint::RADIUS_CHOICES_KM)),
    NearMePoint::RADIUS_CHOICES_KM,
    'The radius choices contain a duplicate.'
    );
    }

    /**
    * The rate limits on emailed action links bound how fast one address can
    * spend the 500-recipient allowance. The guide deliberately does not quote
    * the numbers — it only promises that repeated requests are stopped — so
    * what is asserted here is that the limits still exist and are ordered the
    * way the code orders them.
    */
    public function testTheActionTokenRateLimitsStillBoundRepeatedRequests(): void
    {
    self::assertSame(3600, ActionTokenRateLimiter::WINDOW_SECONDS, 'The rate limit window is no longer an hour.');
    self::assertGreaterThan(0, ActionTokenRateLimiter::EMAIL_REQUEST_LIMIT);
    self::assertGreaterThan(0, ActionTokenRateLimiter::IP_REQUEST_LIMIT);

    self::assertLessThan(
    ActionTokenRateLimiter::IP_REQUEST_LIMIT,
    ActionTokenRateLimiter::EMAIL_REQUEST_LIMIT,
    'One address may now request as often as one IP, so a single mailbox is no longer the tighter bound.'
    );

    self::assertStringContainsString(
    'Repeated requests for one address are rate limited in the normal way',
    self::guide(self::OPERATOR_GUIDE),
    'The operator guide no longer says repeated sign-in requests are rate limited.'
    );
    }

    /**
    * The audit log's retention ceiling is a hard bound in the query, and the
    * guide must not promise a longer window than the query will serve.
    */
    public function testTheAuditLogWindowIsStatedInsideTheCeilingTheQueryEnforces(): void
    {
    self::assertSame(24, AuditQuery::MAXIMUM_WINDOW_MONTHS);

    $guide = self::guide(self::OPERATOR_GUIDE);

    self::assertStringContainsString(
    '**Older than 24 months is gone.**',
    $guide,
    'The operator guide no longer states the audit window ceiling.'
    );
    self::assertStringContainsString('is 24 months', $guide, 'The audit window ceiling sentence changed.');
    }

    /**
    * The job lock must outlive the job budget, or a slow run releases its lock
    * halfway through and a second run starts on the same rows. The guide
    * promises the lock and checkpoint behaviour, so the relationship is
    * asserted rather than left to the constructor.
    */
    public function testTheJobLockOutlivesTheJobBudgetInsideTheHostLimit(): void
    {
    self::assertGreaterThan(
    JobRunner::DEFAULT_TIME_BUDGET_SECONDS,
    JobRunner::DEFAULT_LOCK_TTL_SECONDS,
    'The lock now expires at or before the job budget, so a run can be overtaken.'
    );

    self::assertLessThan(
    90,
    JobRunner::DEFAULT_TIME_BUDGET_SECONDS,
    'The job budget no longer leaves room inside the 90-second host limit.'
    );
    }

    /**
    * A dean cannot reach a WordPress profile page, so the guide must not be
    * the only place that tells them how to change their email frequency.
    *
    * This is the claim that was wrong: the profile field is gated on
    * `adct_pi_review`, which a deanery approver does not hold, so a guide
    * that says "on your WordPress profile page" is unusable for exactly the
    * person most likely to be a dean.
    */
    public function testADeanCannotReachTheProfileEmailPreference(): void
    {
    self::assertNotContains(
    Capabilities::REVIEW,
    Capabilities::roleCapabilities()['deanery_approver'],
    'A deanery approver now holds the review capability, so the profile preference reaches them and'
    . ' the approver guide wording about deans needs rechecking.'
    );
    }

    /**
    * A reviewer can, and the guide tells them to use it, so the three-way
    * split it now describes depends on both halves being true.
    */
    public function testAReviewerCanReachTheProfileEmailPreference(): void
    {
    self::assertContains(
    Capabilities::REVIEW,
    Capabilities::roleCapabilities()['adct_pi_intake_reviewer'],
    'Reviewers lost the review capability, so the approver guide no longer describes them correctly.'
    );
    }

    /**
    * The link a dean actually gets is worded in the code, and the guide quotes
    * the same words, so a change to the button text cannot leave the guide
    * telling a dean to press a link that no longer exists.
    */
    public function testTheGuideQuotesTheEmailFrequencyLinkTheCodeSends(): void
    {
    $link = 'Change how often we email you';

    self::assertStringContainsString(
    "esc_html('" . $link . "')",
    self::source('src/WordPress/Approval/ApprovalNoticeJob.php'),
    'The emailed frequency link no longer reads "' . $link . '".'
    );
    self::assertStringContainsString(
    $link,
    self::guide(self::APPROVER_GUIDE),
    'The approver guide does not name the link a dean is told to press.'
    );
    self::assertStringContainsString(
    $link,
    self::guide(self::OPERATOR_GUIDE),
    'The operator guide does not name the link a dean is told to press.'
    );
    }

    /**
    * The frequency link is only offered when the notice address resolves to
    * exactly one live approver, and the guide says so. Otherwise somebody
    * reads the guide, waits for a link that is never going to arrive, and
    * concludes the plugin is broken.
    *
    * The code gate is `count($userIds) !== 1`, so the guard is asserted
    * against the real expression rather than a paraphrase of it.
    */
    public function testTheGuideExplainsWhenTheFrequencyLinkIsOffered(): void
    {
    self::assertStringContainsString(
    'belongs to exactly one active approver',
    self::guide(self::APPROVER_GUIDE),
    'The approver guide no longer says when the frequency link is offered.'
    );

    self::assertStringContainsString(
    'count($userIds) !== 1',
    self::source('src/WordPress/Approval/ApprovalNoticeJob.php'),
    'The frequency link is no longer restricted to exactly one live approver.'
    );
    }

    /**
    * The handler the emailed frequency link activates exists, and it offers
    * exactly the two modes the guides describe.
    */
    public function testTheFrequencyLinkOffersExactlyTheTwoModesTheGuideDescribes(): void
    {
    self::assertSame(
    'approval_preference',
    NotifyModeChangeHandler::SUBJECT_TYPE,
    'The frequency link no longer activates the approval-preference handler.'
    );
    self::assertSame(
    ['digest', 'each'],
    array_keys(NotifyModeChangeHandler::MODES),
    'The frequency link no longer offers exactly the digest and per-item modes.'
    );

    self::assertStringContainsString(
    'Change how often we email you',
    self::guide(self::OPERATOR_GUIDE),
    'The operator guide no longer names the frequency link.'
    );
    }

    /**
    * The dean's front-end queue asks for a revert rather than performing one.
    *
    * The wording matters: a guide that says the button "emails the revert
    * link" describes a step the plugin does not take. Nothing in src mints a
    * REVERT_CHANGE token (issue #71 owns that), so this test fails the day
    * somebody wires it up, and the guide gets updated then rather than
    * claiming a confirmation email that is not sent.
    */
    public function testTheRevertButtonAsksRatherThanReverting(): void
    {
    $queue = self::source('src/WordPress/Approval/FrontEndApprovalQueue.php');

    self::assertStringContainsString(
    "esc_html__('Ask to revert'",
    $queue,
    'The revert button was renamed.'
    );
    self::assertStringContainsString(
    "'We have asked for a confirmation email before reverting that change.'",
    $queue,
    'The front-end revert confirmation wording changed.'
    );
    self::assertSame('adct_pi_front_queue_revert', FrontEndApprovalQueue::REVERT_ACTION);
    }

    /**
    * The revert handler validates the nonce, confirms the change is in the
    * signed-in person's own scope, and then redirects. It does nothing else:
    * no token minted, no mail queued, no row written. Each of those three
    * absences is what makes the guide's "pressing it never changes an event"
    * honest, and each is asserted separately so wiring up any one of them
    * fails here rather than leaving the guide describing an email that is
    * never sent.
    */
    public function testTheRevertRequestValidatesThenOnlyRedirects(): void
    {
    $body = self::methodBody(
    'src/WordPress/Approval/FrontEndApprovalQueue.php',
    'public function handleRevertRequest'
    );

    self::assertStringContainsString(
    'verifyNonce(self::REVERT_ACTION',
    $body,
    'The revert handler no longer verifies a nonce.'
    );
    self::assertStringContainsString(
    'findScopedChange($changeId, $userId, $email, $reviewer)',
    $body,
    'The revert handler no longer re-checks that the change belongs to the person pressing it.'
    );
    self::assertStringContainsString(
    "\$this->redirect(['revert_requested' => \$changeId]);",
    $body,
    'The revert handler no longer ends in a redirect carrying the change id.'
    );

    foreach (['ActionTokenPurpose', '$this->database->write', 'MailQueue', 'wp_mail'] as $sideEffect) {
    self::assertStringNotContainsString(
    $sideEffect,
    $body,
    'The revert handler now uses ' . $sideEffect . ', so it no longer just asks:'
    . ' the guides must be rewritten because a confirmation email really is sent.'
    );
    }
    }

    /**
    * Exactly one place mints a revert token: the change notice (#71).
    *
    * This replaced a guard that asserted nothing in `src` minted one, which
    * was true only while revert had no way in. Now the notice job mints one
    * per entitled recipient, so the question worth pinning is narrower and
    * more useful: no *other* file may mint one, because a token is the only
    * way to act and a second minting route is a second, unaudited one.
    */
    public function testOnlyTheChangeNoticeMintsARevertToken(): void
    {
    $offenders = [];

    foreach (self::phpFilesIn('src') as $file) {
    if (basename($file) === 'RevertChangeHandler.php') {
    continue;
    }

    if (basename($file) === 'ChangeNoticeJob.php') {
    continue;
    }

    if (str_contains(self::source($file), 'ActionTokenPurpose::REVERT_CHANGE')) {
    $offenders[] = $file;
    }
    }

    self::assertSame(
    [],
    $offenders,
    "A second file now mints a revert token, so only the change notice"
    . " offers the undo route and anything else is a second, unaudited one:\n- "
    . implode("\n- ", $offenders)
    );

    self::assertStringContainsString(
    'ActionTokenPurpose::REVERT_CHANGE',
    self::source('src/WordPress/Change/ChangeNoticeJob.php'),
    'The change notice no longer offers a revert link, so the operator guide'
    . " describing one is stale, and the 'Ask to revert' path leads nowhere."
    );
    }

    /**
    * The handler that performs a revert is registered, and since #71 it has a
    * way in: the change notice mints it a token. Both halves are asserted so
    * a registration that went missing and a minting route that went missing
    * each fail here rather than leaving the operator guide describing a revert
    * that cannot happen.
    */
    public function testTheRevertHandlerIsRegisteredAndHandedTokens(): void
    {
    self::assertStringContainsString(
    'ActionTokenPurpose::REVERT_CHANGE',
    self::source('src/WordPress/Auth/RevertChangeHandler.php'),
    'RevertChangeHandler no longer acts on revert tokens, so the exclusion above is wrong.'
    );
    self::assertStringContainsString(
    'RevertChangeHandler',
    self::source('src/WordPress/Plugin.php'),
    'RevertChangeHandler is no longer registered, so a minted token would act on nothing.'
    );
    self::assertStringContainsString(
    'ActionTokenPurpose::REVERT_CHANGE',
    self::source('src/WordPress/Change/ChangeNoticeJob.php'),
    'Nothing mints a revert token any more, so the front-end queue\'s'
    . " 'Ask to revert' and the operator guide both promise an email that is never sent."
    );
    }

    /**
    * The guide has to describe the button that exists, not the one it wishes
    * existed.
    */
    public function testTheGuideDescribesTheButtonThatIsActuallyRendered(): void
    {
    $guide = self::guide(self::OPERATOR_GUIDE);

    self::assertStringContainsString('**Ask to revert**', $guide);
    self::assertStringNotContainsString(
    '**Ask for the revert link**',
    $guide,
    'The operator guide still names a button that is not rendered.'
    );
    }

    /**
    * A promise of a confirmation email is only honest while nothing is
    * enqueued. Asserted as a negative so the guide and the queue cannot drift
    * apart quietly in either direction.
    */
    public function testTheGuideDoesNotPromiseAnEmailTheQueueDoesNotSend(): void
    {
    self::assertStringNotContainsString(
    'emails the same single-use revert link',
    self::guide(self::OPERATOR_GUIDE)
    );
    }

    /**
    * The two "Recent changes" surfaces are different screens with different
    * states, and a reader must not be told one is the other.
    *
    * The admin tab is an empty placeholder awaiting #71; the front-end list
    * is populated from the dean's own events. The guide says so in different
    * sentences, which is the only thing that keeps the two apart.
    */
    public function testTheTwoRecentChangesSurfacesAreDescribedSeparately(): void
    {
    $guide = self::guide(self::OPERATOR_GUIDE);

    // Admin queue: not yet populated.
    self::assertStringContainsString('**Recent changes** is empty for now', $guide);

    // Front-end queue: populated, with a button per row.
    self::assertStringContainsString('**Recent changes** lists changes to the dean', $guide);
    }

    /**
    * The front-end repository does read recent changes, so the guide's
    * sentence about the dean is not aspirational.
    */
    public function testTheFrontEndQueueDoesReadRecentChanges(): void
    {
    self::assertStringContainsString(
    "esc_html__('Recent changes'",
    self::source('src/WordPress/Approval/FrontEndApprovalQueue.php')
    );
    self::assertStringContainsString(
    'public function recentChanges(',
    self::source('src/WordPress/Database/Repository/ReviewQueueRepository.php'),
    'The front-end Recent changes list has no repository method behind it.'
    );
    }

    /**
    * The admin tab, by contrast, is empty on purpose. If someone fills it in,
    * the sentence that says it is empty is stale.
    */
    public function testTheAdminRecentChangesTabIsStillAnEmptyPlaceholder(): void
    {
    $repository = self::source('src/WordPress/Database/Repository/ReviewQueueRepository.php');

    // The guard returns before it builds any query, so no row can reach the
    // admin tab. Line endings are normalised first: the file is CRLF on
    // Windows and a literal "\n" would never match.
    self::assertMatchesRegularExpression(
    "/\\\$tab === 'recent_changes'\) \{\s*return \[\];/",
    $repository,
    "The admin Recent changes tab no longer returns nothing, so the operator guide's"
    . " 'empty for now' is stale."
    );
    }

    /**
    * Match resolution shipped in #177 as a panel on the candidate page, so the
    * guide must name the control that is rendered and not a future editor.
    */
    public function testMatchResolutionIsPointedAtTheControlThatExists(): void
    {
    $guide = self::guide(self::OPERATOR_GUIDE);

    self::assertStringContainsString('**Resolve this match**', $guide);
    self::assertStringNotContainsString(
    'future candidate editor',
    $guide,
    'The operator guide still describes match resolution as future work that has since shipped.'
    );

    self::assertStringContainsString(
    '<h2>Resolve this match</h2>',
    self::source('src/WordPress/Admin/CandidateDetailView.php'),
    'The candidate page no longer renders the Resolve this match panel.'
    );
    }

    /**
    * The sign-in lifetime constant is described as bounded "from 1 to 3650"
    * with an "absurd value" ignored, but the code only rejects a value below
    * 1. The guide now says so; if the code gains a ceiling, this fails and
    * the guide can be restored to the tighter promise.
    */
    public function testTheGuideDoesNotPromiseACookieCeilingTheCodeDoesNotEnforce(): void
    {
    $guide = self::guide(self::OPERATOR_GUIDE);

    self::assertStringNotContainsString('from 1 to 3650', $guide, 'The operator guide claims a cookie ceiling the code does not enforce.');
    self::assertStringContainsString('There is no upper limit in the code', $guide);
    }

    /**
    * A technical reader will ask what the code actually rejects, so the guide
    * names the two cases and the default that stands in for them.
    */
    public function testTheGuideDescribesWhatTheCookieConstantActuallyRejects(): void
    {
    self::assertStringContainsString(
    'is not a number, or fewer than 1 day',
    self::guide(self::OPERATOR_GUIDE)
    );

    $method = self::methodBody('src/WordPress/Plugin.php', 'public function authCookieExpiration');

    self::assertStringContainsString('! is_numeric($days)', $method, 'A non-numeric lifetime is no longer rejected.');
    self::assertStringContainsString(
    '(int) $days < 1',
    $method,
    'A sub-one-day lifetime is no longer rejected.'
    );
    self::assertStringContainsString(
    'error_log(',
    $method,
    'A rejected lifetime is no longer written to the error log, so the guide\'s claim is wrong.'
    );
    self::assertStringNotContainsString(
    '3650',
    $method,
    'The code now enforces a 3650-day ceiling; restore the tighter wording in the guide.'
    );
    }

    /**
    * Two sections of the guide must not say the same thing twice.
    *
    * The duplicated "Problems." block that sat under the review queue was a
    * copy of "How to find things." with two extra sentences in it. It read as
    * two instructions and left a reader unsure which one applied.
    */
    public function testTheReviewQueueSectionDoesNotRepeatAParagraph(): void
    {
    $paragraphs = [];
    $section = self::section(self::OPERATOR_GUIDE, 'review-approve-and-correct');

    self::assertNotEmpty($section);

    foreach (preg_split('/\R\s*\R/', trim($section)) ?: [] as $paragraph) {
    $normalised = self::normalise($paragraph);

    if ($normalised !== '') {
    $paragraphs[] = $normalised;
    }
    }

    self::assertSame(
    array_values(array_unique($paragraphs)),
    $paragraphs,
    'A paragraph in the review-queue section appears twice.'
    );
    }

    /**
    * No section of any guide may repeat a paragraph. A duplicated block is how
    * the guide came to describe the same screen two ways, one of them wrong.
    */
    public function testNoGuideRepeatsAParagraphVerbatim(): void
    {
    foreach ([self::OPERATOR_GUIDE, self::APPROVER_GUIDE, self::SUBMISSION_GUIDE] as $path) {
    $seen = [];

    foreach (preg_split('/\R\s*\R/', self::guide($path)) ?: [] as $paragraph) {
    $normalised = self::normalise($paragraph);

    // Headings and short labels repeat legitimately; only a real
    // paragraph of prose is worth comparing.
    if (strlen($normalised) < 120 || str_starts_with($normalised, '#')) {
    continue;
    }

    self::assertNotContains(
    $normalised,
    $seen,
    $path . ' repeats a paragraph verbatim: ' . substr($normalised, 0, 80)
    );

    $seen[] = $normalised;
    }
    }
    }

    /**
    * The review-queue section is also the one the help registry anchors to,
    * so its heading must stay exactly as it is. A rename would break every
    * help tab that links to it.
    */
    public function testTheReviewQueueHeadingIsTheOneTheHelpTabsLinkTo(): void
    {
    $heading = "\n## Review, approve and correct\n";

    self::assertStringContainsString(
    $heading,
    self::normaliseLineEndings(self::guide(self::OPERATOR_GUIDE)),
    'The review-queue heading changed, so every help tab that links to it now points nowhere.'
    );

    // And the anchor the help registry uses must still be the one that
    // heading generates, rather than a name that merely looks similar.
    self::assertSame(
    'review-approve-and-correct',
    self::anchorFor('Review, approve and correct'),
    'The anchor rule changed under the help links; AdminHelpRegistryTest will catch the fallout.'
    );
    }

    /**
    * The outbound section claimed there was no operator screen at all, which
    * is not true: the screen exists for Test mode and suppressed mail. What
    * it does not do is list the queue itself.
    */
    public function testTheOutboundScreenIsDescribedAsWhatItIs(): void
    {
    $guide = self::guide(self::OPERATOR_GUIDE);

    self::assertStringContainsString('**Parish Intake → Outbound email**', $guide);
    self::assertStringNotContainsString(
    'there is not yet an operator queue screen',
    $guide,
    'The operator guide still says the outbound screen does not exist.'
    );

    $page = self::source('src/WordPress/Admin/OutboundMailPage.php');

    foreach (['<h1>Outbound email</h1>', '<h2>Test mode</h2>', '<h2>Suppressed mail log</h2>'] as $heading) {
    self::assertStringContainsString(
    $heading,
    $page,
    'The outbound screen no longer renders ' . $heading . '.'
    );
    }
    }

    /**
    * A guide for a parish secretary cannot be written in developer shorthand.
    * A screen reference has to be one a person can follow from the menu, so
    * every **Parish Intake → X** reference has to name a menu the plugin
    * registers.
    *
    * A reference may go one step deeper — "Scheduled jobs → Run now" is a
    * button on the Scheduled jobs screen, not a menu of its own — so only the
    * screen part after the arrow is looked up.
    */
    public function testEveryScreenTheGuideNamesIsAMenuThePluginRegisters(): void
    {
    $labels = self::registeredScreenNames();
    $missing = [];
    $found = 0;

    foreach (self::GUIDES as $guidePath) {
    preg_match_all('/\*\*Parish Intake → ([^*]+?)\*\*/u', self::guide($guidePath), $matches);

    foreach ($matches[1] as $reference) {
    $screen = trim(explode('→', $reference)[0]);
    $found++;

    if (! in_array($screen, $labels, true)) {
    $missing[] = $guidePath . ': ' . $screen;
    }
    }
    }

    self::assertGreaterThan(
    0,
    $found,
    'No screen references were found, so the check would pass vacuously.'
    );

    self::assertSame(
    [],
    $missing,
    "The guides name a screen the plugin does not register:\n- " . implode("\n- ", $missing)
    . "\n\nRegistered: " . implode(', ', $labels)
    );
    }

    /**
    * The guide's "Who can do what" table is how a parish secretary finds the
    * right person, so every role it names has to be a role the plugin knows
    * about. The first column is a label, optionally with the slug in
    * backticks, so both halves are checked against the code.
    */
    public function testEveryRoleTheGuideNamesIsARoleThePluginDefines(): void
    {
    $labels = Capabilities::customRoleLabels();
    $slugs = array_keys($labels);
    $missing = [];
    $found = 0;

    foreach (self::roleTableRows() as $cell) {
    $found++;

    $slug = null;
    if (preg_match('/`([a-z0-9_]+)`/i', $cell, $matches) === 1) {
    $slug = $matches[1];
    }

    $name = trim((string) preg_replace('/\s*\(`.*`\)\s*/', '', $cell));

    if ($slug === null) {
    // A row with no slug is a WordPress built-in. The guide gives
    // built-ins by the name WordPress displays, so it is compared
    // case-insensitively against the plugin's built-in slugs.
    $known = array_map(
    static fn (string $role): string => ucfirst($role),
    Capabilities::builtInRoles()
    );

    if (! in_array(strtolower($name), array_map('strtolower', $known), true)) {
    $missing[] = 'role name "' . $name . '"';
    }

    continue;
    }

    // A row with a slug is one of the plugin's own roles: the label and
    // the slug both have to be the ones the plugin registers.
    if (! in_array($name, $labels, true)) {
    $missing[] = 'role label "' . $name . '" for slug "' . $slug . '"';
    }

    if (! in_array($slug, $slugs, true)) {
    $missing[] = 'role slug "' . $slug . '"';
    }
    }

    self::assertGreaterThan(
    0,
    $found,
    'No rows were read from the role table, so the check would pass vacuously.'
    );

    self::assertSame([], $missing, 'The guide names a role the plugin does not define: ' . implode(', ', $missing));
    }

    /**
    * The first column of the guide's role table, header and separator excluded.
    *
    * Scoped to the section headed "Roles and access" so the other tables in the
    * guide — the troubleshooting table, the mailbox field list — are not
    * mistaken for role names.
    *
    * @return list<string>
    */
    private static function roleTableRows(): array
    {
    $section = self::section(self::OPERATOR_GUIDE, 'roles-and-access');
    $rows = [];

    foreach (preg_split('/\R/', $section) ?: [] as $line) {
    if (preg_match('/^\|\s*([^|]+?)\s*\|/', $line, $matches) !== 1) {
    continue;
    }

    $cell = trim($matches[1]);

    if ($cell === 'Role' || str_starts_with($cell, '-')) {
    continue;
    }

    $rows[] = $cell;
    }

    return $rows;
    }

    /**
    * The public listing and its API are promised to show only published events
    * and no contact details. The guide sentence is pinned to the code that
    * backs it, so a change to the projection fails here rather than quietly
    * starting to expose a sender's address.
    */
    public function testThePublicListingPromisesOnlyPublishedEventsWithoutContactDetails(): void
    {
    self::assertStringContainsString(
    'they never include contact details or source emails',
    self::guide(self::OPERATOR_GUIDE),
    'The operator guide no longer states what the public listing leaves out.'
    );

    $listing = self::source('src/WordPress/Events/PublicEventListing.php');

    self::assertStringContainsString(
    "\$args[] = 'publish';",
    $listing,
    'The public listing no longer filters to published posts.'
    );

    self::assertStringNotContainsStringIgnoringCase(
    'contact_email',
    $listing,
    'The public listing now selects a contact email column, which the guide promises it does not.'
    );
    }

    /**
    * Whitespace and emphasis carry no meaning here, so two paragraphs that
    * differ only in wrapping must compare equal.
    */
    private static function normalise(string $paragraph): string
    {
    $text = preg_replace('/[*`]/', '', $paragraph) ?? $paragraph;

    return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    /**
    * The body of one `## ` section, up to the next one at the same level.
    */
    private static function section(string $markdownPath, string $anchor): string
    {
    $body = [];
    $collecting = false;

    foreach (preg_split('/\R/', self::guide($markdownPath)) ?: [] as $line) {
    if (preg_match('/^##\s+(.*)$/', $line, $matches) === 1) {
    if ($collecting) {
    break;
    }

    $collecting = self::anchorFor($matches[1]) === $anchor;

    continue;
    }

    if ($collecting) {
    $body[] = $line;
    }
    }

    self::assertNotEmpty(
    $body,
    'No section with the anchor ' . $anchor . ' was found in ' . $markdownPath . '.'
    );

    return implode("\n", $body);
    }

    /**
    * GitHub's anchor rule, as AdminHelpRegistryTest implements it.
    */
    private static function anchorFor(string $heading): string
    {
    $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $heading) ?? $heading;
    $text = preg_replace('/[*_`]/', '', $text) ?? $text;
    $text = strtolower(trim($text));
    $text = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $text) ?? $text;

    return preg_replace('/\s+/', '-', trim($text)) ?? $text;
    }

    /**
    * The body of one method, from its signature to the next one.
    *
    * Line endings are normalised first so the body can be searched with "\n"
    * regardless of what the checkout produced.
    */
    private static function methodBody(string $relativePath, string $signature): string
    {
    $contents = self::normaliseLineEndings(self::source($relativePath));
    $start = strpos($contents, $signature);

    self::assertIsInt(
    $start,
    $relativePath . ' no longer contains ' . $signature . ', so a guide sentence about it is stale.'
    );

    $body = substr($contents, $start);
    $next = strpos($body, "\n    public function ", 1);

    return $next === false ? $body : substr($body, 0, $next);
    }

    private static function normaliseLineEndings(string $contents): string
    {
    return str_replace(["\r\n", "\r"], "\n", $contents);
    }

    /**
    * The number a figure's guide sentence actually carries.
    *
    * The number is read back out of the guide's own sentence, so the assertion
    * compares the guide against the code rather than against a second copy of
    * the expected value.
    */
    private static function numberIn(string $asWritten): int|float
    {
    $asWritten = str_replace([',', '`'], '', $asWritten);

    self::assertMatchesRegularExpression('/^\d+(\.\d+)?$/', $asWritten, 'Not a bare number: ' . $asWritten);

    return str_contains($asWritten, '.') ? (float) $asWritten : (int) $asWritten;
    }

    /**
    * The two ends of a "from x to y" range, read back out of the guide's own
    * sentence. Backticks and thousands separators are stripped first, because
    * the mail cap is written with backticks.
    *
    * @return array{0: int, 1: int}|null
    */
    private static function rangeIn(string $sentence): ?array
    {
    if (preg_match('/from\s+`?(\d[\d,]*)`?\s+to\s+`?(\d[\d,]*)`?/i', $sentence, $matches) !== 1) {
    return null;
    }

    return [(int) str_replace(',', '', $matches[1]), (int) str_replace(',', '', $matches[2])];
    }

    /**
    * The number a sentence spells out in words, so "five attempts" is checked
    * against MAX_ATTEMPTS rather than merely required to be present.
    */
    private static function spelledNumberIn(string $sentence): ?int
    {
    static $words = [
    'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
    'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
    ];

    $found = [];

    foreach (preg_split('/[^a-z]+/i', strtolower($sentence)) ?: [] as $word) {
    if (isset($words[$word])) {
    $found[] = $words[$word];
    }
    }

    self::assertLessThanOrEqual(
    1,
    count($found),
    'Expected exactly one number spelled in words in "' . $sentence
    . '", found ' . implode(', ', array_keys($words)) . ' mapping to ' . implode(', ', $found)
    . '. Adjust the sentence or the constant rather than loosening this.'
    );

    return $found[0] ?? null;
    }

    /**
    * A duration written in hours and/or minutes, converted back to minutes.
    *
    * The guide says "capped at 6 hours" and "backoff starts at 10 minutes"; the
    * constants are both in minutes, so the guide's own phrase is converted
    * rather than the sentence being matched against a literal.
    */
    private static function asMinutes(string $sentence): ?int
    {
    $total = 0;
    $matched = false;

    if (preg_match_all('/(\d+)\s*(hour|minute)s?/i', $sentence, $matches, PREG_SET_ORDER) < 1) {
    return null;
    }

    foreach ($matches as $match) {
    $matched = true;
    $total += (int) $match[1] * (strtolower($match[2]) === 'hour' ? 60 : 1);
    }

    return $matched ? $total : null;
    }

    /**
    * The minutes and the hours of a "1,440 minutes (24 hours)" style figure,
    * read back out of the guide's own sentence so the two units can each be
    * checked against the constant.
    *
    * @return array{0: int, 1: int}|null
    */
    private static function minutesAndHoursIn(string $sentence): ?array
    {
    if (preg_match('/([\d,]+)\s*minutes?\s*\(\s*([\d,]+)\s*hours?\s*\)/i', $sentence, $matches) !== 1) {
    return null;
    }

    return [
    (int) str_replace(',', '', $matches[1]),
    (int) str_replace(',', '', $matches[2]),
    ];
    }

    /**
    * The hour of a "07:00" style clock time, so the guide's own zero padding
    * and hour are compared with the constant.
    */
    private static function clockTimeIn(string $sentence): ?int
    {
    if (preg_match('/\b(\d{1,2}):(\d{2})\b/', $sentence, $matches) !== 1) {
    return null;
    }

    return (int) $matches[1];
    }

    /**
    * The distances in a "to 5, 10, 25, 50 or 100 km" style list, read back out
    * of the guide's own sentence.
    *
    * @return list<float>|null
    */
    private static function kmListIn(string $sentence): ?array
    {
    // The ladder is written as "5, 10, 25, 50 or 100 km", so the run of
    // numbers must be allowed to span both the commas and the " or ".
    if (preg_match('/((?:\d+\s*(?:,\s*|\s+or\s+)?)+)\s*km/i', $sentence, $matches) !== 1) {
    return null;
    }

    preg_match_all('/\d+/', str_replace([',', ' or '], ' ', $matches[1]), $numbers);

    if ($numbers[0] === []) {
    return null;
    }

    return array_map(static fn (string $number): float => (float) $number, $numbers[0]);
    }

    private static function asHoursAndMinutes(int $seconds): string
    {
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);

    if ($minutes === 0) {
    return $hours === 1 ? '1 hour' : $hours . ' hours';
    }

    if ($hours === 0) {
    return $minutes === 1 ? '1 minute' : $minutes . ' minutes';
    }

    return $hours . ' hours ' . $minutes . ' minutes';
    }

    private static function asClockTime(int $hour): string
    {
    return sprintf('%02d:00', $hour);
    }

    /**
    * The labels the plugin registers, read out of its own source.
    *
    * Read from the add_menu_page and add_submenu_page calls rather than
    * listed here, so a renamed menu is caught instead of being duplicated
    * into the test.
    *
    * @return list<string>
    */
    private static function registeredMenuLabels(): array
    {
    $labels = [];

    foreach (self::phpFilesIn('src/WordPress') as $file) {
    if (preg_match_all(
    "/add_(?:sub)?menu_page\(\s*[^,]+,\s*('[^']+'|\"[^\"]+\")/",
    self::source($file),
    $found
    ) < 1) {
    continue;
    }

    foreach ($found[1] as $label) {
    $labels[] = trim($label, "'\"");
    }
    }

    self::assertNotEmpty($labels, 'No menu labels were read, so the screen check would pass vacuously.');

    return array_values(array_unique($labels));
    }

    /**
    * The names an operator sees in the menu for every screen the plugin adds.
    *
    * add_submenu_page takes a page title and a shorter menu label, and
    * WordPress shows the shorter one, so both are collected: the guide may
    * name a screen by either. The plugin's own top-level page is collected
    * too, because a first-time operator sees "Parish Intake" and its screens
    * underneath.
    *
    * @return list<string>
    */
    private static function registeredScreenNames(): array
    {
    $names = [];

    foreach (self::phpFilesIn('src/WordPress') as $file) {
    $contents = self::normaliseLineEndings(self::source($file));

    // Both name arguments of add_(sub)menu_page: the page title and the
    // shorter label WordPress actually shows in the menu.
    // Single quotes throughout: a double-quoted fragment would turn
    // "\$" into a bare "$", which is a regex end anchor, not a dollar.
    preg_match_all(
    '/add_(?:sub)?menu_page\(\s*'
    . '\'[^\']*\',\s*'
    . '\'([^\']*)\',\s*'
    . '(\'[^\']*\'|\$([a-zA-Z_]+))/x',
    $contents,
    $found,
    PREG_SET_ORDER
    );

    foreach ($found as $match) {
    if ($match[1] !== '') {
    $names[] = $match[1];
    }

    $argument = $match[2];

    // A literal menu label, e.g. 'Settings'.
    if (str_starts_with($argument, '\'')) {
    $literal = trim($argument, '\'');

    if ($literal !== '') {
    $names[] = $literal;
    }

    continue;
    }

    // The label was built first and passed in — the review queue
    // appends a pending-count badge to "Review queue". Only the
    // variable actually used as a label is followed, so an unrelated
    // string assignment cannot be mistaken for a screen name.
    $variable = $match[3];

    if ($variable === '') {
    continue;
    }

    // The label is a concatenation ('Review queue' . ($badge > 0
    // ? ' <span…>' : '')), so read the leading literal of the
    // assignment rather than requiring the whole value to be one.
    if (preg_match(
    '/\$' . preg_quote($variable, '/') . '\s*=\s*\'([^\']+?)\'/',
    $contents,
    $assigned
    ) === 1) {
    $names[] = $assigned[1];
    }
    }
    }

    // The shorter label is only ever the tail of the full page title
    // ("Parish Intake Review queue" is shown as "Review queue"), so the tail
    // counts as the name an operator sees.
    $shortNames = [];
    foreach ($names as $name) {
    $shortNames[] = $name;
    $shortNames[] = (string) preg_replace('/^Parish Intake\s+/', '', $name);
    }

    self::assertNotEmpty(
    $shortNames,
    'No menu names were read, so the screen check would pass vacuously.'
    );

    return array_values(array_unique($shortNames));
    }

    private static function guide(string $relativePath): string
    {
    return self::readable($relativePath, 'guide');
    }

    private static function source(string $relativePath): string
    {
    return self::readable($relativePath, 'source');
    }

    private static function readable(string $relativePath, string $what): string
    {
    $path = dirname(__DIR__, 4) . '/' . $relativePath;

    self::assertFileExists($path, 'The ' . $what . ' ' . $relativePath . ' could not be found.');

    $contents = file_get_contents($path);
    self::assertIsString($contents, 'The ' . $what . ' ' . $relativePath . ' could not be read.');

    return $contents;
    }

    /**
    * @return list<string>
    */
    private static function phpFilesIn(string $relativeDirectory): array
    {
    $base = dirname(__DIR__, 4);
    $found = [];

    $iterator = new \RecursiveIteratorIterator(
    new \RecursiveDirectoryIterator($base . '/' . $relativeDirectory, \FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
    if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
    $found[] = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
    }
    }

    self::assertNotEmpty($found, 'No PHP files were found under ' . $relativeDirectory . '.');

    sort($found);

    return $found;
    }
    }
