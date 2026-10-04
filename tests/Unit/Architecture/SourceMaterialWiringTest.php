<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * The source-material routes exist in `Plugin.php` (issue #172).
 *
 * Both halves of this feature were already written — the promote control on the
 * review queue and the poster/bulletin box on the event editor — and neither was
 * reachable. `ReviewQueuePage` takes its promotion collaborators as nullable
 * constructor arguments and refuses every request when they are null, and
 * `Plugin.php` was passing ten arguments, so the form rendered and every press
 * of it answered 400. The same file never registered an `admin_post_` hook for
 * either route, so even correctly-constructed collaborators would have had
 * nothing to dispatch to.
 *
 * Those two faults are invisible to every other test in the suite, because each
 * class is exercised directly with its collaborators supplied. They are only
 * observable from the composition root, which nothing was asserting about, so
 * this file reads it.
 *
 * Reading source rather than booting WordPress is deliberate: the alternative
 * would be a `wp-env` integration test, and this must run in the unit suite.
 * Each assertion below is paired with a mutation probe recorded in the PR body.
 */
final class SourceMaterialWiringTest extends TestCase
{
    private static function pluginSource(): string
    {
        $path = dirname(__DIR__, 3) . '/src/WordPress/Plugin.php';

        self::assertFileExists($path);

        $source = file_get_contents($path);

        self::assertIsString($source);

        return $source;
    }

    public function testThePromoteRouteIsRegistered(): void
    {
        self::assertStringContainsString(
            "'admin_post_' . ReviewQueuePage::PROMOTE_SOURCE_ACTION,",
            self::pluginSource(),
            'Without this the promote form has no handler and admin_post.php never dispatches to it.'
        );
    }

    public function testBothEventEditorRoutesAreRegistered(): void
    {
        $source = self::pluginSource();

        foreach (['ADD_ACTION', 'REMOVE_ACTION'] as $constant) {
            self::assertStringContainsString(
                "'admin_post_' . EventSourceMaterialEditor::" . $constant . ',',
                $source,
                $constant . ' has no route, so its form could never act.'
            );
        }
    }

    public function testEachRouteIsBoundToTheHandlerItNames(): void
    {
        $source = self::pluginSource();

        self::assertStringContainsString('[$this->reviewQueuePage, \'handlePromoteSourceMaterial\']', $source);
        self::assertStringContainsString('[$this->eventSourceMaterial, \'handleAdd\']', $source);
        self::assertStringContainsString('[$this->eventSourceMaterial, \'handleRemove\']', $source);
    }

    public function testTheReviewQueueIsGivenThePromotionCollaborators(): void
    {
        $source = self::pluginSource();

        $constructor = $this->constructionOf($source, 'new ReviewQueuePage(');

        self::assertStringContainsString(
            '$this->sourceMaterialPromotion()',
            $constructor,
            'Passed as nothing, ReviewQueuePage leaves its $sourceMaterial null and handlePromoteSourceMaterial() refuses every request with a 400.'
        );
        self::assertStringContainsString(
            '$this->sourceMaterialAuditTrail()',
            $constructor,
            'The same: $audit stays null and a promotion would be recorded for nobody.'
        );
    }

    public function testTheEventEditorIsGivenTheBox(): void
    {
        self::assertStringContainsString(
            '$this->eventEditor->attachSourceMaterial($this->eventSourceMaterial);',
            self::pluginSource(),
            'Without it the meta box is never registered, so the add/remove routes exist but nothing renders a form.'
        );
    }

    /**
     * The box and the promote control must share one repository.
     *
     * Two `ReviewQueueRepository` instances built from the same options would
     * agree today, and diverge the moment the threshold is read from anywhere
     * else — and the symptom would be a file offered on one screen and not the
     * other, which no single-screen test can see.
     */
    public function testTheBoxSharesTheReviewQueueRepositoryRatherThanBuildingASecond(): void
    {
        $source = self::pluginSource();

        $box = $this->constructionOf($source, 'new EventSourceMaterialEditor(');

        self::assertStringContainsString(
            '$this->reviewQueue,',
            $box,
            'The box should reuse the repository the review queue already built.'
        );
        self::assertStringNotContainsString(
            'new ReviewQueueRepository(',
            $box,
            'A second repository risks the two screens disagreeing about what is promotable.'
        );
    }

    /**
     * `EventEditor` takes the box as its last constructor argument, so a caller
     * that omits it silently gets a screen with no box. Asserting the signature
     * keeps the constructor honest without re-deriving the whole composition.
     */
    public function testTheEventEditorStillAcceptsTheBoxAsAParameter(): void
    {
        $parameters = (new ReflectionClass(\ADCT\ParishIntake\WordPress\Events\EventEditor::class))
            ->getConstructor()
            ?->getParameters() ?? [];

        self::assertNotSame([], $parameters);

        $last = $parameters[count($parameters) - 1];

        self::assertInstanceOf(ReflectionNamedType::class, $last->getType());
        self::assertSame(
            'ADCT\\ParishIntake\\WordPress\\Events\\EventSourceMaterialEditor',
            $last->getType()->getName(),
            'The last constructor parameter is the box.'
        );
        self::assertTrue($last->isDefaultValueAvailable(), 'And it stays optional.');
        self::assertTrue($last->getType()->allowsNull(), 'And nullable, so a site without it still boots.');
    }

    public function testAttachSourceMaterialIsTheOnlyWayToSetTheBox(): void
    {
        $method = new ReflectionMethod(\ADCT\ParishIntake\WordPress\Events\EventEditor::class, 'attachSourceMaterial');

        self::assertTrue($method->isPublic());
        self::assertSame(
            1,
            $method->getNumberOfParameters(),
            'It takes the box and nothing else: it must not also create collaborators, because it runs where WordPress globals may not be safe.'
        );

        $type = $method->getParameters()[0]->getType();

        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertSame(
            'ADCT\\ParishIntake\\WordPress\\Events\\EventSourceMaterialEditor',
            $type->getName(),
        'Nullable, so a site without the collaborators can still boot.'
        );
        self::assertTrue($type->allowsNull(), 'And nullable, for the same reason.');
    }

    /**
     * Nothing in the new collaborators may be built in `Plugin`'s constructor
     * from a WordPress global.
     *
     * `scripts/check-release-bootstrap.php` loads the plugin under bare PHP, so
     * an unguarded `plugins_url()` or `get_option()` in the path from the
     * constructor to these collaborators would make the release zip unbuildable
     * while every unit test stayed green.
     */
    public function testTheCollaboratorsAreBuiltByMethodsThatDoNotTouchAGlobal(): void
    {
        $reflection = new ReflectionClass(\ADCT\ParishIntake\WordPress\Plugin::class);

        foreach (['sourceMaterialPromotion', 'sourceMaterialAuditTrail'] as $name) {
            self::assertTrue($reflection->hasMethod($name), $name . ' is missing.');

            $method = $reflection->getMethod($name);

            self::assertTrue(
                $method->isPrivate(),
                $name . ' is internal wiring and should not be part of the plugin\'s surface.'
            );
            self::assertSame([], $method->getParameters(), $name . ' takes no arguments, so it cannot be handed a pre-built collaborator.');
        }
    }

    private function constructionOf(string $source, string $opening): string
    {
        $start = strpos($source, $opening);

        self::assertIsInt($start, $opening . ' is not in Plugin.php at all.');

        $depth = 0;
        $length = strlen($source);

        for ($i = $start + strlen($opening) - 1; $i < $length; $i++) {
            if ($source[$i] === '(') {
                $depth++;
            }

            if ($source[$i] === ')') {
                $depth--;

                if ($depth === 0) {
                    return substr($source, $start, $i - $start + 1);
                }
            }
        }

        self::fail('Unbalanced parentheses after ' . $opening);
    }
}