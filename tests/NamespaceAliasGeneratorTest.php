<?php

declare(strict_types=1);

namespace Webong\ComposerSource\Tests;

use PHPUnit\Framework\TestCase;

final class NamespaceAliasGeneratorTest extends TestCase
{
    public function testPackageMetadataUsesTheExpectedNamespaceAliasShape(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('webong/composer-source-plugin', $composer['name']);
        self::assertSame(
            'Webong\\ComposerSource\\ComposerSourcePlugin',
            $composer['extra']['class'],
        );
    }

    public function testGeneratedAliasesAreRegisteredForOptimizedComposerAutoloading(): void
    {
        $generator = (string) file_get_contents(__DIR__ . '/../src/NamespaceAliasGenerator.php');

        self::assertStringContainsString('registerStaticAutoloadFile', $generator);
        self::assertStringContainsString("'/autoload_static.php'", $generator);
    }

    public function testRebasedAutoloadPathsAreResolvedRelativeToTheGeneratedFile(): void
    {
        $generator = (string) file_get_contents(__DIR__ . '/../src/NamespaceAliasGenerator.php');

        self::assertStringContainsString('$rebasedRelativeRoot = \'rebased/\'', $generator);
        self::assertStringContainsString('$file = __DIR__ . \'/\' . $path', $generator);
        self::assertStringNotContainsString('$paths[] = $rebasedPath', $generator);
    }
}
