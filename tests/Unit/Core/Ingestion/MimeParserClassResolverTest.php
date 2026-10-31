<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Ingestion;

use ADCT\ParishIntake\Core\Ingestion\MimeParserClassResolver;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class MimeParserClassResolverTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPrefixedClassIsPreferredWhenBothNamesResolve(): void
    {
        $unprefixedClass = 'ZBateson\\MailMimeParser\\MailMimeParser';
        $prefixedClass = 'ADCT\\ParishIntake\\Dependencies\\ZBateson\\MailMimeParser\\MailMimeParser';

        self::assertTrue(class_exists($unprefixedClass));

        if (! class_exists($prefixedClass, false)) {
            self::assertTrue(class_alias($unprefixedClass, $prefixedClass));
        }

        self::assertSame($prefixedClass, MimeParserClassResolver::resolve());
    }

    public function testMimeParserClassListAndResolverAreDefinedOnlyOnceInCore(): void
    {
        $coreDirectory = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core';
        $resolverFiles = [];
        $consumerCalls = 0;

        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($coreDirectory, FilesystemIterator::SKIP_DOTS)
        ) as $sourceFile) {
            if (! $sourceFile->isFile() || $sourceFile->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($sourceFile->getPathname());

            if (preg_match('/\\bfinal\\s+class\\s+MimeParserClassResolver\\b/', $source) === 1) {
                $resolverFiles[] = $sourceFile->getPathname();
            }

            $consumerCalls += preg_match_all('/MimeParserClassResolver::resolve\\(\\)/', $source);
        }

        self::assertCount(1, $resolverFiles, 'The MIME parser resolver class must have one definition in Core.');
        self::assertSame(2, $consumerCalls, 'Both MIME parser consumers must use the shared resolver.');

        $resolverSource = (string) file_get_contents($resolverFiles[0]);
        self::assertSame(
            1,
            preg_match_all('/private const MIME_PARSER_CLASSES\\b/', $resolverSource),
            'The MIME parser class list must be defined once in its resolver.'
        );
        self::assertSame(
            1,
            preg_match_all('/public static function resolve\\s*\\(/', $resolverSource),
            'The MIME parser resolver must define its own entry point once.'
        );
    }
}
