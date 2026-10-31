<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Admin;

use ADCT\ParishIntake\Core\Auth\Capabilities;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Sources\SourceRegistryService;
use ADCT\ParishIntake\Core\Sources\SourceRole;
use ADCT\ParishIntake\Core\Sources\SourceStatus;
use ADCT\ParishIntake\WordPress\Admin\SourcesPage;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use ADCT\ParishIntake\WordPress\Database\Repository\ParishRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\SourceRepository;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../Support/WordPressStubs.php';
require_once __DIR__ . '/../../../Support/WordPressCapabilityStubs.php';
require_once __DIR__ . '/../../../Support/AdminWordPressStubs.php';

final class SourcesPageTest extends TestCase
{
    public function testInvalidTypeWithBlankIntervalReturns400WithoutSavingSource(): void
    {
        $originalPost = $_POST;
        $originalGlobals = [];
        foreach (['adct_test_wp_caps', 'adct_test_nonce_checks', 'adct_test_nonce_should_fail'] as $key) {
            $originalGlobals[$key] = [
                'present' => array_key_exists($key, $GLOBALS),
                'value' => $GLOBALS[$key] ?? null,
            ];
        }

        $database = $this->createMock(DatabaseConnectionInterface::class);
        $database->expects(self::never())->method('query');
        $database->expects(self::never())->method('getRow');
        $clock = $this->createMock(ClockInterface::class);
        $clock->expects(self::never())->method('now');
        $sources = new SourceRepository($database);
        $page = new SourcesPage(
            $sources,
            new SourceRegistryService($sources, $clock),
            new ParishRepository($database)
        );

        $_POST = [
            'source_id' => '0',
            'parish_id' => '0',
            'source_nonce' => 'valid-test-nonce',
            'type' => 'unsupported',
            'identifier' => 'intake@example.test',
            'role' => SourceRole::OFFICIAL,
            'status' => SourceStatus::ACTIVE,
            'poll_interval_minutes' => '',
        ];
        $GLOBALS['adct_test_wp_caps'] = [Capabilities::MANAGE_DIRECTORY];
        $GLOBALS['adct_test_nonce_checks'] = [];
        $GLOBALS['adct_test_nonce_should_fail'] = false;

        try {
            try {
                $page->handleSaveSource();
                self::fail('The invalid source type was accepted.');
            } catch (\AdctTestWpDie $failure) {
                self::assertSame(400, $failure->status);
                self::assertSame('Choose a supported source type.', $failure->getMessage());
            }

            self::assertSame([
                ['action' => 'adct_pi_source_save_0_0', 'name' => 'source_nonce'],
            ], $GLOBALS['adct_test_nonce_checks']);
        } finally {
            $_POST = $originalPost;
            foreach ($originalGlobals as $key => $original) {
                if ($original['present']) {
                    $GLOBALS[$key] = $original['value'];
                } else {
                    unset($GLOBALS[$key]);
                }
            }
        }
    }
}
