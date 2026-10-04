<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Events;

use ADCT\ParishIntake\Core\Events\EventValidator;
use ADCT\ParishIntake\Core\Events\RRulePresetMapper;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use ADCT\ParishIntake\WordPress\Database\Repository\ParishRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\VenueRepository;
use ADCT\ParishIntake\WordPress\Events\EventEditor;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

/**
 * Which files the public side serves is decided by the admin promote action, which
 * checks a capability and a nonce and writes an audit row. If `validateRestRequest()`
 * let `source_attachment_ids` through, the block editor's meta endpoint would be a
 * second, unaudited way to point the event page at any attachment in the library,
 * so the key is refused here rather than merely left out of REST_META_FIELDS.
 *
 * "Left out of REST_META_FIELDS" is not enough on its own: that list only decides
 * which submitted values are merged into what gets validated and stored. An
 * unrecognised key would still ride along in `$incoming` and be written straight
 * through by the normalisation step below, so the refusal has to be its own arm.
 */
final class EventEditorSourceMaterialRestGuardTest extends TestCase
{
    public function testSourceMaterialIsRefusedThroughTheRestApi(): void
    {
        $result = $this->validate([
            'source_attachment_ids' => [['attachment_id' => 812, 'role' => 'poster', 'name' => 'poster.jpg']],
        ]);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('adct_event_private_source_material', $result->get_error_code());
        self::assertSame(400, $result->get_error_data()['status'] ?? null);
    }

    /**
     * An empty list is still a change: it is how a caller would try to clear the
     * event's files. The refusal must not be conditional on the value.
     */
    public function testAnEmptySourceMaterialListIsRefusedToo(): void
    {
        $result = $this->validate(['source_attachment_ids' => []]);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('adct_event_private_source_material', $result->get_error_code());
    }

    /**
     * The same reason the key is kept out of REST_META_FIELDS: an unrecognised
     * submitted key would otherwise be passed through untouched.
     */
    public function testTheKeyIsNotInTheSetTheRestEndpointValidatesAndStores(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../../src/WordPress/Events/EventEditor.php');

        self::assertIsString($source);

        preg_match('/REST_META_FIELDS = \[(.*?)\];/s', $source, $matches);

        self::assertArrayHasKey(1, $matches, 'REST_META_FIELDS was not found');

        self::assertStringNotContainsString(
            'source_attachment_ids',
            $matches[1],
            'source_attachment_ids must not be in REST_META_FIELDS: that list merges submitted ' .
            'values into what is stored'
        );
    }

    /**
     * @param array<string, mixed> $meta
     * @return mixed
     */
    private function validate(array $meta)
    {
        $timezone = new DateTimeZone('Africa/Johannesburg');
        $editor = new EventEditor(
            new ParishRepository(new SilentDatabase()),
            new VenueRepository(new SilentDatabase()),
            new EventValidator($timezone),
            new RRulePresetMapper(),
            $timezone,
            new FrozenClock()
        );

        $request = new \WP_REST_Request(['id' => 5]);
        $request->set_param('meta', $meta);

        return $editor->validateRestRequest(null, $request);
    }
}

final class FrozenClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-05 09:00:00', new DateTimeZone('Africa/Johannesburg'));
    }
}

/**
 * Nothing should be queried: every arm this test exercises returns before the
 * validator needs the database, and a query here would mean the guard did not.
 */
final class SilentDatabase implements DatabaseConnectionInterface
{
    public string $error = '';

    public function prefix(): string
    {
        return 'wp_';
    }

    public function prepare(string $query, mixed ...$arguments): string
    {
        throw new \RuntimeException('The REST guard queried the database: ' . $query);
    }

    public function query(string $query): int|false
    {
        throw new \RuntimeException('The REST guard ran a query: ' . $query);
    }

    public function getRow(string $query): ?array
    {
        throw new \RuntimeException('The REST guard read a row: ' . $query);
    }

    public function getResults(string $query): array
    {
        throw new \RuntimeException('The REST guard read rows: ' . $query);
    }

    public function escapeLike(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    public function insertId(): int
    {
        return 0;
    }

    public function charsetCollate(): string
    {
        return 'utf8mb4_unicode_ci';
    }

    public function clearLastError(): void
    {
    }

    public function lastError(): string
    {
        return $this->error;
    }
}
