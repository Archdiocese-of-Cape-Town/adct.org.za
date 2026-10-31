<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Events;

use ADCT\ParishIntake\Core\Audit\AuditAction;
use ADCT\ParishIntake\Core\Audit\AuditSubjectType;
use ADCT\ParishIntake\WordPress\Events\EventEditor;
use ADCT\ParishIntake\WordPress\Plugin;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class EventEditorAuditWiringTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPluginConstructedEventEditorRegistersAndReadsItsAuditTrail(): void
    {
        require_once __DIR__ . '/../../../Support/WordPressEventEditorStubs.php';
        require_once __DIR__ . '/../../../Support/AdminWordPressStubs.php';

        define('ARRAY_A', 'ARRAY_A');
        $database = new EventAuditWpdb();
        $GLOBALS['wpdb'] = $database;
        $GLOBALS['adct_test_post_caps'] = true;
        $GLOBALS['adct_test_meta_boxes'] = [];

        // Use the real composition root, not a second hand-written EventEditor
        // call. Reflection only opens its private constructor and reads the result;
        // none of the collaborators are replaced after construction.
        $reflection = new ReflectionClass(Plugin::class);
        $plugin = $reflection->newInstanceWithoutConstructor();
        $reflection->getConstructor()->invoke(
            $plugin,
            dirname(__DIR__, 4) . '/adct-parish-intake.php'
        );
        $editor = $reflection->getProperty('eventEditor')->getValue($plugin);
        self::assertInstanceOf(EventEditor::class, $editor);
        self::assertSame([], $database->reads, 'Construction must not query WordPress.');

        $editor->registerMetaBox();

        self::assertArrayHasKey(
            'adct_event_audit',
            $GLOBALS['adct_test_meta_boxes'],
            'Plugin must supply the optional audit panel to EventEditor.'
        );
        $box = $GLOBALS['adct_test_meta_boxes']['adct_event_audit'];
        self::assertSame('adct_event', $box['screen']);
        self::assertSame('Audit trail', $box['title']);
        self::assertSame([$editor, 'renderAuditMetaBox'], $box['callback']);

        ob_start();
        try {
            ($box['callback'])(new \WP_Post(77));
        } finally {
            $markup = (string) ob_get_clean();
        }

        self::assertStringContainsString('Event published', $markup);
        self::assertStringContainsString('Synthetic event audit evidence', $markup);
        self::assertStringNotContainsString('<form', $markup);
        self::assertStringNotContainsString('nonce', $markup);
        self::assertCount(1, $database->reads);
        self::assertStringContainsString('adct_pi_audit_log', $database->reads[0]);
        self::assertStringContainsString('subject_type = %s', $database->reads[0]);
        self::assertStringContainsString('subject_id = %d', $database->reads[0]);
        self::assertSame(
            [AuditSubjectType::EVENT, 77, 25, 0],
            array_slice($database->arguments, -4),
            'The real panel and repository must read this event, with bounded paging.'
        );
    }
}

/** Only the read-only wpdb surface used by the real audit repository. */
final class EventAuditWpdb
{
    public string $prefix = 'wp_';
    public string $last_error = '';

    /** @var list<string> */
    public array $reads = [];

    /** @var list<mixed> */
    public array $arguments = [];

    public function prepare(string $query, mixed ...$arguments): string
    {
        $this->arguments = $arguments;

        return $query;
    }

    /** @return list<array<string, mixed>> */
    public function get_results(string $query, string $output): array
    {
        $this->reads[] = $query;

        return [[
            'id' => 1,
            'actor' => 'reviewer@example.test',
            'action' => AuditAction::EVENT_PUBLISHED->value,
            'subject_type' => AuditSubjectType::EVENT,
            'subject_id' => 77,
            'details' => '{"title":"Synthetic event audit evidence"}',
            'created_at' => '2026-10-09 15:04:05',
        ]];
    }
}
