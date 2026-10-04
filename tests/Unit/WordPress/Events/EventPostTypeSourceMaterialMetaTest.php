<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Events {

use ADCT\ParishIntake\Core\Attachments\SourceMaterialRole;
use ADCT\ParishIntake\WordPress\Attachments\WordPressSourceMaterialStore;
use ADCT\ParishIntake\WordPress\Events\EventPostType;
use PHPUnit\Framework\TestCase;

/**
 * The `source_attachment_ids` post meta registration (issue #172).
 *
 * Registered rather than written raw, because an unregistered meta key is
 * invisible to `sanitize_meta()`, so whatever value reached `update_post_meta()`
 * would be stored verbatim. Registering it gives the key a sanitiser and the
 * editor's `auth_callback`, which is what the issue's own notes ask for.
 */
final class EventPostTypeSourceMaterialMetaTest extends TestCase
{
    public function testTheDefinitionUsesTheSameKeyTheStoreWritesAndTheFrontEndReads(): void
    {
        self::assertSame(
            WordPressSourceMaterialStore::META_KEY,
            EventPostType::SOURCE_MATERIAL_META_KEY
        );
        self::assertSame('source_attachment_ids', EventPostType::SOURCE_MATERIAL_META_KEY);
    }

    public function testTheDefinitionIsAnArrayOfObjectsOnAnArrayKey(): void
    {
        $definition = EventPostType::sourceMaterialMetaDefinition();

        self::assertSame('array', $definition['type']);
        self::assertTrue($definition['single']);
    }

    /**
     * Not exposed in REST at all. Promotion is a deliberate human action on an
     * admin screen, so the key must not be readable or writable through the
     * block editor's meta endpoint: a REST write here would be a way to publish
     * a file nobody approved.
     */
    public function testTheDefinitionIsNotExposedInRest(): void
    {
        self::assertFalse(EventPostType::sourceMaterialMetaDefinition()['show_in_rest']);
    }

    /**
     * The issue's notes are explicit: the editor's own auth callback, not a
     * bare one. A bare callback (or none) would let any user with edit_posts
     * rewrite the list of files the public side is allowed to serve.
     */
    public function testTheDefinitionCarriesTheEditorAuthCallback(): void
    {
        $definition = EventPostType::sourceMaterialMetaDefinition();

        self::assertArrayHasKey('auth_callback', $definition);
        self::assertTrue(is_callable($definition['auth_callback']));
    }

    public function testTheAuthCallbackGrantsOnlyToSomebodyWhoCanEditThatEvent(): void
    {
        $callback = EventPostType::sourceMaterialMetaDefinition()['auth_callback'];

        // WordPressEventEditorStubs.php answers current_user_can() from
        // adct_test_post_caps. Its comment is the reason this is worth stating:
        // the stub keeps the per-post answer separate from the actor-level list
        // on purpose, so that the two can disagree and the guard can mean
        // something. A verdict for a bare 'edit_post' with no post in hand is
        // what this callback is handed, so the per-post global is the one that
        // has to drive it.
        $GLOBALS['adct_test_post_caps'] = true;

        try {
            self::assertTrue((bool) $callback(true, 'source_attachment_ids', 412, 9, 'edit_post', []));

            $GLOBALS['adct_test_post_caps'] = false;
            self::assertFalse((bool) $callback(true, 'source_attachment_ids', 412, 9, 'edit_post', []));

            // An unsaved post has no id, so there is nothing to be allowed to
            // edit and the answer is no even for a capable user.
            $GLOBALS['adct_test_post_caps'] = true;
            self::assertFalse((bool) $callback(true, 'source_attachment_ids', 0, 9, 'edit_post', []));
        } finally {
            unset($GLOBALS['adct_test_post_caps']);
        }
    }

    public function testTheSanitiserKeepsTheOrderAndTheRolesOfWellFormedEntries(): void
    {
        self::assertSame([
            ['attachment_id' => 901, 'role' => 'poster', 'name' => 'poster.jpg'],
            ['attachment_id' => 902, 'role' => 'bulletin', 'name' => 'notice.pdf'],
        ], EventPostType::sanitizeSourceMaterial([
            ['attachment_id' => 901, 'role' => 'poster', 'name' => 'poster.jpg'],
            ['attachment_id' => 902, 'role' => 'bulletin', 'name' => 'notice.pdf'],
        ]));
    }

    /**
     * The sanitiser's one power is removal. It must never be able to add an
         * entry, and must never be able to keep one it does not understand: an
         * id it cannot read, a negative id, a role outside the closed list, or an
         * entry with no id at all.
         */
        public function testTheSanitiserDropsEntriesRatherThanRepairingThem(): void
        {
            self::assertSame([
                // A well-formed entry with no name is kept: the name is optional
                // metadata, and the caller may not have had one.
                ['attachment_id' => 904, 'role' => 'document', 'name' => ''],
            ], EventPostType::sanitizeSourceMaterial([
                ['attachment_id' => 0, 'role' => 'poster', 'name' => ''],
                ['attachment_id' => -5, 'role' => 'bulletin', 'name' => ''],
                ['attachment_id' => 903, 'role' => 'everything', 'name' => ''],
                ['attachment_id' => 904, 'role' => SourceMaterialRole::DOCUMENT],
                ['role' => 'document'],
            ]));
        }

    public function testTheSanitiserCollapsesARepeatedAttachmentIdToItsFirstEntry(): void
    {
        self::assertSame([
            ['attachment_id' => 901, 'role' => 'poster', 'name' => 'poster.jpg'],
        ], EventPostType::sanitizeSourceMaterial([
            ['attachment_id' => 901, 'role' => 'poster', 'name' => 'poster.jpg'],
            ['attachment_id' => 901, 'role' => 'bulletin', 'name' => 'poster.jpg'],
        ]));
    }

    /**
     * A hand-edited or corrupt value must not take the whole page down, so
     * anything unrecognisable becomes no entries rather than a fatal error.
     */
    public function testTheSanitiserSurvivesValuesThatAreNotAList(): void
    {
        self::assertSame([], EventPostType::sanitizeSourceMaterial(null));
        self::assertSame([], EventPostType::sanitizeSourceMaterial('not json'));
        self::assertSame([], EventPostType::sanitizeSourceMaterial(['attachment_id' => 901]));
        self::assertSame([], EventPostType::sanitizeSourceMaterial(42));
    }

    /**
     * A JSON-encoded value is a shape WordPress itself can produce when a meta
     * key is registered as a single array, so it has to decode rather than be
     * thrown away.
     */
    public function testTheSanitiserDecodesAJsonEncodedValue(): void
    {
        self::assertSame([
            ['attachment_id' => 901, 'role' => 'poster', 'name' => ''],
        ], EventPostType::sanitizeSourceMaterial(
            '[{"attachment_id":901,"role":"poster","name":""}]'
        ));
    }
}
}
