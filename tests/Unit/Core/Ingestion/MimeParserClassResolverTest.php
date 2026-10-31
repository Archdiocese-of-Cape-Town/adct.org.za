<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Ingestion;

use ADCT\ParishIntake\Core\Ingestion\MimeParserClassResolver;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class MimeParserClassResolverTest extends TestCase
{
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
        $classListDeclarations = 0;
        $resolverDeclarations = 0;
        $consumerCalls = 0;

        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($coreDirectory, FilesystemIterator::SKIP_DOTS)
        ) as $sourceFile) {
            if (! $sourceFile->isFile() || $sourceFile->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($sourceFile->getPathname());
            $classListDeclarations += preg_match_all('/private const MIME_PARSER_CLASSES\\b/', $source);
            $resolverDeclarations += preg_match_all('/public static function resolve\\s*\\(/', $source);
            $consumerCalls += preg_match_all('/MimeParserClassResolver::resolve\\(\\)/', $source);
        }

        self::assertSame(1, $classListDeclarations, 'The MIME parser class list must have one definition in Core.');
        self::assertSame(1, $resolverDeclarations, 'The MIME parser resolver must have one definition in Core.');
        self::assertSame(2, $consumerCalls, 'Both MIME parser consumers must use the shared resolver.');
    }
}
