<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Events;

use ADCT\ParishIntake\Core\Events\EventDetails;
use ADCT\ParishIntake\Core\Events\EventValidator;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class EventValidatorTest extends TestCase
{
    public function testAcceptsAnEventWhoseEndIsEqualToItsStart(): void
    {
        $details = $this->details([
            'endLocal' => '2026-10-02T09:00',
        ]);

        $result = $this->validator()->validate($details);

        self::assertTrue($result->isValid());
        self::assertSame([], $result->errors);
        self::assertSame('2026-10-02T09:00', $result->values['end_local']);
    }

    public function testRejectsAnEndBeforeTheStartAndUnsupportedStatus(): void
    {
        $result = $this->validator()->validate($this->details([
            'endLocal' => '2026-10-02T08:59',
            'statusFlag' => 'draft',
        ]));

        self::assertFalse($result->isValid());
        self::assertContains('The event end must be on or after its start.', $result->errors);
        self::assertContains('The event status must be scheduled, cancelled or postponed.', $result->errors);
    }

    public function testNormalizesAllDayTimesAndExceptionDatesToLocalMidnight(): void
    {
        $result = $this->validator()->validate($this->details([
            'startLocal' => '2026-10-02T18:30',
            'endLocal' => '2026-10-02T00:00',
            'allDay' => true,
            'exdates' => ['2026-10-09T16:00'],
            'rdates' => ['2026-10-16T08:30'],
        ]));

        self::assertTrue($result->isValid());
        self::assertSame('2026-10-02T00:00', $result->values['start_local']);
        self::assertSame('2026-10-02T00:00', $result->values['end_local']);
        self::assertSame(['2026-10-09T00:00'], $result->values['exdates']);
        self::assertSame(['2026-10-16T00:00'], $result->values['rdates']);
    }

    public function testRejectsInvalidLocalDatesAndNonListExceptionDates(): void
    {
        $result = $this->validator()->validate($this->details([
            'startLocal' => '2026-02-30T09:00',
            'exdates' => ['not-a-local-datetime'],
            'rdates' => ['named-date' => '2026-10-02T09:00'],
        ]));

        self::assertFalse($result->isValid());
        self::assertContains('The event start must be a valid local date and time.', $result->errors);
        self::assertContains('Each exception date must be a valid local date and time.', $result->errors);
        self::assertContains('Additional dates must be a list of local date and times.', $result->errors);
    }

    public function testRequiresAParishWhenAVenueIsSelected(): void
    {
        $result = $this->validator()->validate($this->details([
            'parishId' => null,
            'venueId' => 23,
        ]));

        self::assertFalse($result->isValid());
        self::assertContains('Choose a parish before selecting a venue.', $result->errors);
    }

    public function testRejectsAnInvalidContactEmail(): void
    {
        $result = $this->validator()->validate($this->details([
            'contact' => [
                'name' => 'Fictional Contact',
                'email' => 'not-an-email',
                'phone' => '021 555 0101',
            ],
        ]));

        self::assertFalse($result->isValid());
        self::assertContains('The event contact email address is invalid.', $result->errors);
    }

    private function validator(): EventValidator
    {
        return new EventValidator(new DateTimeZone('Africa/Johannesburg'));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function details(array $overrides = []): EventDetails
    {
        $values = array_merge([
            'parishId' => 9,
            'venueId' => 19,
            'startLocal' => '2026-10-02T09:00',
            'endLocal' => '2026-10-02T10:00',
            'allDay' => false,
            'rrule' => null,
            'exdates' => [],
            'rdates' => [],
            'featured' => false,
            'statusFlag' => 'scheduled',
            'sourceCandidateId' => null,
            'contact' => [],
        ], $overrides);

        return new EventDetails(
            $values['parishId'],
            $values['venueId'],
            $values['startLocal'],
            $values['endLocal'],
            $values['allDay'],
            $values['rrule'],
            $values['exdates'],
            $values['rdates'],
            $values['featured'],
            $values['statusFlag'],
            $values['sourceCandidateId'],
            $values['contact']
        );
    }
}
