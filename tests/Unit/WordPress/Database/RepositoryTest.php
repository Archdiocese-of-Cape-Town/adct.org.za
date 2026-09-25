<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Database;

use ADCT\ParishIntake\Core\Approval\ApprovalRoute;
use ADCT\ParishIntake\Core\Approval\ApproverSettings;
use ADCT\ParishIntake\Core\Ingestion\MailboxCheckpoint;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use ADCT\ParishIntake\WordPress\Database\Repository\ApprovalRouteRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\DeaneryApproverRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\DeaneryRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\EventCandidateRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\InboundMessageRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\MailboxRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\ParishContactRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\ParishRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\SourceRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\VenueRepository;
use ADCT\ParishIntake\WordPress\Directory\DirectoryVersionStoreInterface;
use ADCT\ParishIntake\Core\Directory\SenderTrust;
use ADCT\ParishIntake\Core\Directory\Venue;
use ADCT\ParishIntake\Core\Sources\Source;
use ADCT\ParishIntake\Core\Sources\SourceHealthState;
use ADCT\ParishIntake\Core\Sources\SourceRole;
use ADCT\ParishIntake\Core\Sources\SourceStatus;
use ADCT\ParishIntake\Core\Sources\SourceType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RepositoryTest extends TestCase
{
    public function testRepositoryCrudUsesPreparedQueriesAndAllowsNullValues(): void
    {
        $database = new FakeDatabaseConnection();
        $database->nextInsertId = 12;
        $repository = new ParishRepository($database);

        $insertedId = $repository->insert([
            'name' => 'Sample Parish',
            'slug' => 'sample-parish',
            'deanery_id' => null,
            'created_at' => '2026-09-25 00:00:00',
            'updated_at' => '2026-09-25 00:00:00',
        ]);
        $repository->findById(12);
        $repository->update(12, ['status' => 'inactive', 'deanery_id' => null]);
        $repository->delete(12);

        self::assertSame(12, $insertedId);
        self::assertCount(4, $database->preparedQueries);
        self::assertStringContainsString('INSERT INTO wp_adct_pi_parishes', $database->preparedQueries[0]['query']);
        self::assertStringContainsString('`deanery_id` = NULL', $database->preparedQueries[2]['query']);
        self::assertSame([12], $database->preparedQueries[1]['arguments']);
        self::assertStringContainsString('UPDATE wp_adct_pi_parishes', $database->preparedQueries[2]['query']);
        self::assertStringContainsString('DELETE FROM wp_adct_pi_parishes', $database->preparedQueries[3]['query']);
    }

    public function testParishVenueAndContactWritesBumpTheDirectoryVersion(): void
    {
        $versions = new FakeDirectoryVersionStore();

        $parishDatabase = new FakeDatabaseConnection();
        $parishDatabase->nextInsertId = 12;
        $parishes = new ParishRepository($parishDatabase, $versions);
        $parishes->insert(['name' => 'Sample Parish']);
        $parishes->update(12, ['name' => 'Updated Sample Parish']);
        $parishes->delete(12);

        $venueDatabase = new FakeDatabaseConnection();
        $venueDatabase->nextInsertId = 22;
        $venues = new VenueRepository($venueDatabase, $versions);
        $venues->insert(['name' => 'Sample Hall']);
        $venues->update(22, ['name' => 'Updated Sample Hall']);
        $venues->delete(22);

        $contactDatabase = new FakeDatabaseConnection();
        $contacts = new ParishContactRepository($contactDatabase, $versions);
        $contacts->saveLink(12, 'sender@example.test', '', '', true, SenderTrust::UNKNOWN, null, '2026-09-25 00:00:00');
        $contacts->setTrustForEmail(
            'sender@example.test',
            SenderTrust::VERIFIED,
            '2026-09-25 00:00:00',
            '2026-09-25 00:00:00'
        );
        $contacts->deleteLink(22, 12);

        self::assertSame(9, $versions->bumps);
    }

    public function testAllRequiredRepositoriesUseTheirVersionedTableNames(): void
    {
        $repositoryClasses = [
            DeaneryRepository::class,
            ParishRepository::class,
            SourceRepository::class,
            InboundMessageRepository::class,
            EventCandidateRepository::class,
            VenueRepository::class,
        ];
        $expectedTables = [
            'wp_adct_pi_deaneries',
            'wp_adct_pi_parishes',
            'wp_adct_pi_sources',
            'wp_adct_pi_inbound_messages',
            'wp_adct_pi_event_candidates',
            'wp_adct_pi_venues',
        ];

        foreach ($repositoryClasses as $index => $repositoryClass) {
            $database = new FakeDatabaseConnection();
            $repository = new $repositoryClass($database);
            $repository->findById(1);

            self::assertStringContainsString(
                $expectedTables[$index],
                $database->preparedQueries[0]['query']
            );
        }
    }

    public function testRepositoriesRejectColumnsOutsideTheirAllowlist(): void
    {
        $database = new FakeDatabaseConnection();
        $repository = new DeaneryRepository($database);

        try {
            $repository->insert(['name; DROP TABLE wp_users' => 'invalid']);
            self::fail('An unknown column was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame([], $database->preparedQueries);
            self::assertSame('The requested database column is not writable.', $exception->getMessage());
        }
    }

    public function testParishDirectoryQueriesUsePreparedFiltersAndStablePagination(): void
    {
        $database = new FakeDatabaseConnection();
        $repository = new ParishRepository($database);

        $repository->findForDirectory([
            'search' => '100%_name',
            'kind' => 'outstation',
            'deanery_id' => 3,
            'status' => 'active',
        ], 25, 50);

        $query = $database->preparedQueries[0];
        self::assertStringContainsString('p.name LIKE %s', $query['query']);
        self::assertStringContainsString('p.slug LIKE %s', $query['query']);
        self::assertStringContainsString('p.area LIKE %s', $query['query']);
        self::assertStringContainsString('p.suburb LIKE %s', $query['query']);
        self::assertStringContainsString('ORDER BY p.name ASC, p.slug ASC LIMIT %d OFFSET %d', $query['query']);
        self::assertSame([
            '%100\\%\\_name%',
            '%100\\%\\_name%',
            '%100\\%\\_name%',
            '%100\\%\\_name%',
            'outstation',
            3,
            'active',
            25,
            50,
        ], $query['arguments']);
    }

    public function testParishBulkDeaneryAssignmentUsesPreparedIdsAndSupportsNoDeanery(): void
    {
        $database = new FakeDatabaseConnection();
        $repository = new ParishRepository($database);
        $timestamp = '2026-09-25 00:00:00';

        $repository->updateDeaneryForParishes([3, 4], 12, $timestamp);
        $repository->updateDeaneryForParishes([5], null, $timestamp);

        self::assertCount(2, $database->preparedQueries);
        self::assertStringContainsString(
            'UPDATE wp_adct_pi_parishes SET deanery_id = %d, updated_at = %s WHERE id IN (%d, %d)',
            $database->preparedQueries[0]['query']
        );
        self::assertSame([12, $timestamp, 3, 4], $database->preparedQueries[0]['arguments']);
        self::assertStringContainsString(
            'UPDATE wp_adct_pi_parishes SET deanery_id = NULL, updated_at = %s WHERE id IN (%d)',
            $database->preparedQueries[1]['query']
        );
        self::assertSame([$timestamp, 5], $database->preparedQueries[1]['arguments']);
    }

    public function testDeaneryDirectoryQueryIncludesActiveApproverCounts(): void
    {
        $database = new FakeDatabaseConnection();
        $database->resultRows = [['id' => 12, 'active_approver_count' => '2']];
        $repository = new DeaneryRepository($database);

        $rows = $repository->findAllWithActiveApproverCounts();

        self::assertSame('2', $rows[0]['active_approver_count']);
        self::assertStringContainsString('FROM wp_adct_pi_deaneries d', $database->selectedQueries[0]);
        self::assertStringContainsString('wp_adct_pi_deanery_approvers', $database->selectedQueries[0]);
        self::assertStringContainsString('a.active = 1', $database->selectedQueries[0]);
    }

    public function testDeaneryApproverRepositoryUsesPreparedAssignmentQueries(): void
    {
        $database = new FakeDatabaseConnection();
        $database->nextInsertId = 21;
        $database->rowResult = null;
        $repository = new DeaneryApproverRepository($database);
        $settings = new ApproverSettings(
            'approver@example.test',
            'Dean',
            ApproverSettings::NOTIFY_DIGEST,
            false,
            true
        );
        $timestamp = '2026-09-25 00:00:00';

        $assignmentId = $repository->save(12, 101, $settings, $timestamp);
        $repository->findForDeanery(12);
        $database->rowResult = ['total' => '2'];
        $activeCount = $repository->countActiveForUser(101);

        self::assertSame(21, $assignmentId);
        self::assertSame(2, $activeCount);
        self::assertCount(4, $database->preparedQueries);
        self::assertSame(
            [12, 101],
            $database->preparedQueries[0]['arguments']
        );
        self::assertStringContainsString(
            'INSERT INTO wp_adct_pi_deanery_approvers',
            $database->preparedQueries[1]['query']
        );
        self::assertSame([
            12,
            101,
            'approver@example.test',
            'Dean',
            'digest',
            0,
            1,
            $timestamp,
            $timestamp,
        ], $database->preparedQueries[1]['arguments']);
        self::assertStringContainsString(
            'LEFT JOIN wp_users u ON u.ID = a.wp_user_id',
            $database->preparedQueries[2]['query']
        );
        self::assertSame([12], $database->preparedQueries[2]['arguments']);
        self::assertStringContainsString('WHERE wp_user_id = %d AND active = %d', $database->preparedQueries[3]['query']);
        self::assertSame([101, 1], $database->preparedQueries[3]['arguments']);
    }

    public function testApprovalRouteRepositoryMapsOneParishAndAllApproverRows(): void
    {
        $database = new FakeDatabaseConnection();
        $database->resultRows = [
            [
                'deanery_id' => '12',
                'deanery_status' => 'active',
                'approver_id' => '31',
                'wp_user_id' => '101',
                'email' => 'one@example.test',
                'label' => 'Dean',
                'notify_mode' => 'each',
                'reminders_enabled' => '1',
                'active' => '1',
            ],
            [
                'deanery_id' => '12',
                'deanery_status' => 'active',
                'approver_id' => '32',
                'wp_user_id' => '102',
                'email' => 'two@example.test',
                'label' => 'Assistant',
                'notify_mode' => 'digest',
                'reminders_enabled' => '0',
                'active' => '0',
            ],
        ];

        $snapshot = (new ApprovalRouteRepository($database))->findForParish(7);

        self::assertNotNull($snapshot);
        self::assertSame(12, $snapshot->deaneryId);
        self::assertTrue($snapshot->deaneryActive);
        self::assertCount(2, $snapshot->approvers);
        self::assertSame(ApprovalRoute::REASON_OK, (new \ADCT\ParishIntake\Core\Approval\ApprovalRouteResolver(
            new ApprovalRouteRepository($database)
        ))->forParish(7)->reason);
        self::assertFalse($snapshot->approvers[1]->active);
        self::assertSame('digest', $snapshot->approvers[1]->notifyMode);
        self::assertCount(2, $database->preparedQueries);
        self::assertSame([7], $database->preparedQueries[0]['arguments']);
        self::assertStringContainsString(
            'LEFT JOIN wp_adct_pi_deanery_approvers a ON a.deanery_id = d.id',
            $database->preparedQueries[0]['query']
        );
    }

    public function testVerifiedOfficeContactInsertIsIdempotentAndNeverUpdatesExistingTrust(): void
    {
        $database = new FakeDatabaseConnection();
        $repository = new ParishContactRepository($database);

        self::assertTrue($repository->insertVerifiedIfMissing(
            7,
            'OFFICE@EXAMPLE.INVALID',
            '2026-09-25 00:00:00'
        ));

        self::assertCount(1, $database->executedQueries);
        self::assertStringContainsString('trust, verified_at', $database->executedQueries[0]);
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE id = id', $database->executedQueries[0]);
        self::assertStringNotContainsString('trust =', $database->executedQueries[0]);
        self::assertStringNotContainsString('verified_at =', $database->executedQueries[0]);
        self::assertSame([
            7,
            'office@example.invalid',
            '2026-09-25 00:00:00',
            '2026-09-25 00:00:00',
            '2026-09-25 00:00:00',
        ], $database->preparedQueries[0]['arguments']);
    }

    public function testParishContactAddressReadsAndTrustWritesUsePreparedQueries(): void
    {
        $database = new FakeDatabaseConnection();
        $repository = new ParishContactRepository($database);

        $repository->findByEmail('sender@example.test');
        $repository->saveLink(
            7,
            'sender@example.test',
            'Sample Sender',
            'Secretary',
            true,
            SenderTrust::UNKNOWN,
            null,
            '2026-09-25 00:00:00'
        );
        $repository->setTrustForEmail(
            'sender@example.test',
            SenderTrust::BLOCKED,
            null,
            '2026-09-25 00:00:00'
        );

        self::assertCount(3, $database->preparedQueries);
        self::assertStringContainsString('WHERE email = %s', $database->preparedQueries[0]['query']);
        self::assertSame(['sender@example.test'], $database->preparedQueries[0]['arguments']);
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $database->preparedQueries[1]['query']);
        self::assertSame([
            7,
            'sender@example.test',
            'Sample Sender',
            'Secretary',
            SenderTrust::UNKNOWN,
            1,
            '2026-09-25 00:00:00',
            '2026-09-25 00:00:00',
        ], $database->preparedQueries[1]['arguments']);
        self::assertStringContainsString(
            'UPDATE wp_adct_pi_parish_contacts SET trust = %s, verified_at = NULL',
            $database->preparedQueries[2]['query']
        );
        self::assertSame([
            SenderTrust::BLOCKED,
            '2026-09-25 00:00:00',
            'sender@example.test',
        ], $database->preparedQueries[2]['arguments']);
    }

    public function testVenueDefaultWritesArePreparedAndScopedToTheParish(): void
    {
        $database = new FakeDatabaseConnection();
        $database->rowResult = [
            'id' => '12',
            'parish_id' => '7',
            'status' => 'active',
        ];
        $repository = new VenueRepository($database);

        $repository->setDefaultForParish(7, 12, '2026-09-25 00:00:00');

        self::assertCount(4, $database->preparedQueries);
        self::assertStringContainsString(
            'SELECT id FROM wp_adct_pi_parishes WHERE id = %d FOR UPDATE',
            $database->preparedQueries[0]['query']
        );
        self::assertSame([7], $database->preparedQueries[0]['arguments']);
        self::assertStringContainsString(
            'SELECT id, parish_id, status FROM wp_adct_pi_venues',
            $database->preparedQueries[1]['query']
        );
        self::assertSame([12], $database->preparedQueries[1]['arguments']);
        self::assertStringContainsString(
            'UPDATE wp_adct_pi_venues SET is_default = 0',
            $database->preparedQueries[2]['query']
        );
        self::assertSame(['2026-09-25 00:00:00', 7], $database->preparedQueries[2]['arguments']);
        self::assertStringContainsString(
            'UPDATE wp_adct_pi_venues SET is_default = 1',
            $database->preparedQueries[3]['query']
        );
        self::assertSame(['2026-09-25 00:00:00', 12, 7], $database->preparedQueries[3]['arguments']);
    }

    public function testImportedVenueInsertIsIdempotentBySourceParish(): void
    {
        $database = new FakeDatabaseConnection();
        $repository = new VenueRepository($database);
        $venue = new Venue(
            0,
            7,
            'Sample Outstation',
            ['Sample Area: St Mark'],
            '2 Example Road',
            'Sample Area',
            -33.8,
            18.5,
            false,
            Venue::ACTIVE,
            18
        );

        $repository->insertImportedVenue($venue, '2026-09-25 00:00:00');

        self::assertCount(1, $database->preparedQueries);
        self::assertStringContainsString(
            'INSERT INTO wp_adct_pi_venues',
            $database->preparedQueries[0]['query']
        );
        self::assertStringContainsString(
            'ON DUPLICATE KEY UPDATE id = id',
            $database->preparedQueries[0]['query']
        );
        self::assertSame(18, $database->preparedQueries[0]['arguments'][1]);
    }

    public function testSettingAnOfficialParishSourceDemotesThePreviousOneAndUpdatesTheParishPointer(): void
    {
        $database = new FakeDatabaseConnection();
        $database->nextInsertId = 22;
        $database->rowResults = [
            ['id' => '7'],
            null,
            [
                'id' => '22',
                'parish_id' => '7',
                'type' => SourceType::ICS,
                'identifier' => 'https://example.test/calendar.ics',
                'role' => SourceRole::OFFICIAL,
                'status' => SourceStatus::ACTIVE,
                'poll_interval_minutes' => '1440',
                'last_checked_at' => null,
                'last_success_at' => null,
                'last_item_at' => null,
                'consecutive_failures' => '0',
                'last_error' => null,
            ],
        ];
        $repository = new SourceRepository($database);
        $source = new Source(
            0,
            7,
            SourceType::ICS,
            'https://example.test/calendar.ics',
            SourceRole::OFFICIAL
        );

        $saved = $repository->saveSource($source, '2026-09-25 00:00:00');

        self::assertSame(22, $saved->id);
        self::assertSame(SourceRole::OFFICIAL, $saved->role);
        self::assertCount(6, $database->preparedQueries);
        self::assertStringContainsString(
            'SELECT id FROM wp_adct_pi_parishes WHERE id = %d LIMIT 1 FOR UPDATE',
            $database->preparedQueries[0]['query']
        );
        self::assertStringContainsString(
            'INSERT INTO wp_adct_pi_sources',
            $database->preparedQueries[2]['query']
        );
        self::assertStringContainsString(
            'UPDATE wp_adct_pi_sources SET role = %s',
            $database->preparedQueries[3]['query']
        );
        self::assertSame([
            SourceRole::MONITORED,
            '2026-09-25 00:00:00',
            7,
            SourceRole::OFFICIAL,
            22,
        ], $database->preparedQueries[3]['arguments']);
        self::assertStringContainsString(
            'UPDATE wp_adct_pi_parishes SET official_source_id = %d',
            $database->preparedQueries[4]['query']
        );
        self::assertSame([22, '2026-09-25 00:00:00', 7], $database->preparedQueries[4]['arguments']);
        self::assertSame('START TRANSACTION', $database->executedQueries[0]);
        self::assertSame('COMMIT', $database->executedQueries[count($database->executedQueries) - 1]);
    }

    public function testSourceHealthWritesUsePreparedOptimisticUpdates(): void
    {
        $database = new FakeDatabaseConnection();
        $repository = new SourceRepository($database);
        $expected = new SourceHealthState(
            SourceStatus::ACTIVE,
            '2026-09-24 00:00:00',
            '2026-09-24 00:00:00',
            null,
            1,
            'temporary failure'
        );
        $replacement = new SourceHealthState(
            SourceStatus::UNRELIABLE,
            '2026-09-25 00:00:00',
            '2026-09-24 00:00:00',
            null,
            5,
            'latest failure'
        );

        self::assertTrue($repository->saveHealthIfUnchanged(
            42,
            $expected,
            $replacement,
            '2026-09-25 00:00:00'
        ));

        self::assertCount(1, $database->preparedQueries);
        self::assertStringContainsString(
            'UPDATE wp_adct_pi_sources SET status = %s, last_checked_at = %s, last_success_at = %s, '
            . 'last_item_at = NULL, consecutive_failures = %d, last_error = %s, updated_at = %s '
            . 'WHERE id = %d AND status = %s AND consecutive_failures = %d '
            . 'AND last_checked_at = %s AND last_success_at = %s AND last_item_at IS NULL AND last_error = %s',
            $database->preparedQueries[0]['query']
        );
        self::assertSame([
            SourceStatus::UNRELIABLE,
            '2026-09-25 00:00:00',
            '2026-09-24 00:00:00',
            5,
            'latest failure',
            '2026-09-25 00:00:00',
            42,
            SourceStatus::ACTIVE,
            1,
            '2026-09-24 00:00:00',
            '2026-09-24 00:00:00',
            'temporary failure',
        ], $database->preparedQueries[0]['arguments']);
    }

    public function testSourceMailboxCheckpointUsesTheExistingPreparedJsonColumn(): void
    {
        $database = new FakeDatabaseConnection();
        $database->rowResult = ['checkpoint' => '{"uidvalidity":12345,"last_uid":67}'];
        $repository = new SourceRepository($database);

        self::assertEquals(new MailboxCheckpoint(12345, 67), $repository->findCheckpoint(42));
        $repository->saveCheckpoint(42, new MailboxCheckpoint(54321, 3), '2026-09-25 04:00:00');

        self::assertCount(2, $database->preparedQueries);
        self::assertStringContainsString(
            'SELECT checkpoint FROM wp_adct_pi_sources WHERE id = %d',
            $database->preparedQueries[0]['query']
        );
        self::assertStringContainsString(
            'UPDATE wp_adct_pi_sources SET checkpoint = %s, updated_at = %s WHERE id = %d',
            $database->preparedQueries[1]['query']
        );
        self::assertSame(
            ['{"uidvalidity":54321,"last_uid":3}', '2026-09-25 04:00:00', 42],
            $database->preparedQueries[1]['arguments']
        );
    }

    public function testMailboxRepositorySelectsOnlyActiveArchdioceseEmailMailboxes(): void
    {
        $database = new FakeDatabaseConnection();
        $repository = new MailboxRepository($database);

        self::assertSame([], $repository->findActiveMailboxes());
        self::assertStringContainsString('m.active = 1', $database->selectedQueries[0]);
        self::assertStringContainsString("s.type = 'email'", $database->selectedQueries[0]);
        self::assertStringContainsString('s.parish_id IS NULL', $database->selectedQueries[0]);
        self::assertStringContainsString("s.status = 'active'", $database->selectedQueries[0]);
    }

    public function testInboundDuplicateLookupChecksMessageIdAndContentHashWithinSource(): void
    {
        $database = new FakeDatabaseConnection();
        $database->rowResult = ['id' => '31'];
        $repository = new InboundMessageRepository($database);

        self::assertSame(31, $repository->findDuplicate(7, '<example@example.test>', str_repeat('a', 64)));
        self::assertSame(
            [7, '<example@example.test>', str_repeat('a', 64), '<example@example.test>'],
            $database->preparedQueries[0]['arguments']
        );
        self::assertStringContainsString('source_id = %d', $database->preparedQueries[0]['query']);
        self::assertStringContainsString(
            'external_id = %s OR content_hash = %s',
            $database->preparedQueries[0]['query']
        );
    }

    public function testSourceAdminQueriesFilterWithPreparedValuesAndStablePagination(): void
    {
        $database = new FakeDatabaseConnection();
        $repository = new SourceRepository($database);

        $repository->findForAdmin([
            'search' => '100%_source',
            'parish_id' => 7,
            'type' => SourceType::ICS,
            'role' => SourceRole::OFFICIAL,
            'status' => SourceStatus::ACTIVE,
        ], 20, 40);

        self::assertCount(1, $database->preparedQueries);
        self::assertStringContainsString('s.identifier LIKE %s OR p.name LIKE %s', $database->preparedQueries[0]['query']);
        self::assertStringContainsString('s.parish_id = %d', $database->preparedQueries[0]['query']);
        self::assertStringContainsString('ORDER BY (s.parish_id IS NULL) DESC', $database->preparedQueries[0]['query']);
        self::assertStringContainsString('LIMIT %d OFFSET %d', $database->preparedQueries[0]['query']);
        self::assertSame([
            '%100\\%\\_source%',
            '%100\\%\\_source%',
            7,
            SourceType::ICS,
            SourceRole::OFFICIAL,
            SourceStatus::ACTIVE,
            20,
            40,
        ], $database->preparedQueries[0]['arguments']);
    }

    public function testActiveVenueReadMapsAliasesAndCoordinates(): void
    {
        $database = new FakeDatabaseConnection();
        $database->resultRows = [[
            'id' => '21',
            'parish_id' => '9',
            'name' => 'Sample Hall',
            'aliases' => '["Community Hall"]',
            'address' => '3 Example Road',
            'suburb' => 'Sample Suburb',
            'latitude' => '-33.900000',
            'longitude' => '18.400000',
            'is_default' => '1',
            'status' => 'active',
            'source_parish_id' => null,
        ]];
        $repository = new VenueRepository($database);

        $venues = $repository->findActiveVenues();

        self::assertCount(1, $venues);
        self::assertSame(21, $venues[0]->id);
        self::assertSame(['Community Hall'], $venues[0]->aliases);
        self::assertSame(-33.9, $venues[0]->latitude);
        self::assertSame(18.4, $venues[0]->longitude);
        self::assertTrue($venues[0]->isDefault);
    }

    public function testSenderDirectorySearchAndLinkedParishReadsArePrepared(): void
    {
        $database = new FakeDatabaseConnection();
        $repository = new ParishContactRepository($database);

        $repository->findSenderAddresses([
            'search' => 'a%_',
            'trust' => SenderTrust::PENDING,
        ], 20, 0);
        $repository->findSenderLinksByEmails(['sender@example.test']);

        self::assertCount(2, $database->preparedQueries);
        self::assertStringContainsString('c.email LIKE %s OR c.display_name LIKE %s', $database->preparedQueries[0]['query']);
        self::assertStringContainsString('GROUP BY c.email ORDER BY c.email ASC LIMIT %d OFFSET %d', $database->preparedQueries[0]['query']);
        self::assertSame([
            '%a\\%\\_%',
            '%a\\%\\_%',
            '%a\\%\\_%',
            SenderTrust::PENDING,
            20,
            0,
        ], $database->preparedQueries[0]['arguments']);
        self::assertStringContainsString('WHERE c.email IN (%s)', $database->preparedQueries[1]['query']);
        self::assertSame(['sender@example.test'], $database->preparedQueries[1]['arguments']);
    }
}

final class FakeDatabaseConnection implements DatabaseConnectionInterface
{
    /**
     * @var array<int, array{query: string, arguments: array<int, mixed>}>
     */
    public array $preparedQueries = [];

    /**
     * @var string[]
     */
    public array $executedQueries = [];

    /**
     * @var list<string>
     */
    public array $selectedQueries = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $resultRows = [];

    /**
     * @var array<int, array<string, mixed>|null>
     */
    public array $rowResults = [];

    public ?array $rowResult = ['id' => 1];

    public int $nextInsertId = 1;

    public function prefix(): string
    {
        return 'wp_';
    }

    public function prepare(string $query, mixed ...$arguments): string
    {
        $this->preparedQueries[] = ['query' => $query, 'arguments' => $arguments];

        return $query;
    }

    public function query(string $query): int|false
    {
        $this->executedQueries[] = $query;

        return 1;
    }

    public function getRow(string $query): ?array
    {
        $this->selectedQueries[] = $query;

        if ($this->rowResults !== []) {
            return array_shift($this->rowResults);
        }

        return $this->rowResult;
    }

    public function getResults(string $query): array
    {
        $this->selectedQueries[] = $query;

        return $this->resultRows;
    }

    public function escapeLike(string $text): string
    {
        return addcslashes($text, '\\_%');
    }

    public function insertId(): int
    {
        return $this->nextInsertId;
    }

    public function charsetCollate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    public function clearLastError(): void
    {
    }

    public function lastError(): string
    {
        return '';
    }
}

final class FakeDirectoryVersionStore implements DirectoryVersionStoreInterface
{
    public int $bumps = 0;

    public function current(): int
    {
        return $this->bumps;
    }

    public function bump(): int
    {
        return ++$this->bumps;
    }
}
