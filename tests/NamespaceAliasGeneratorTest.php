<?php

declare(strict_types=1);

namespace Webong\ComposerSource\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webong\ComposerSource\NamespaceAliasGenerator;

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

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function excludedPathProvider(): iterable
    {
        yield 'phpstan extension directory' => ['src/PhpStan/CachedRelationMacroExtension.php', true];
        yield 'lowercase phpstan directory' => ['src/phpstan/Helper.php', true];
        yield 'top level phpstan directory' => ['PhpStan/Helper.php', true];
        yield 'runtime concern' => ['src/Concerns/CachesRelations.php', false];
        yield 'runtime support class' => ['src/Support/CachedRelationRegistry.php', false];
        yield 'runtime contract' => ['src/Contracts/Cache.php', false];
        yield 'helpers file' => ['src/helpers.php', false];
        yield 'tests directory' => ['tests/Feature/CachesRelationsTest.php', true];
        yield 'unrelated similarly named directory' => ['src/Support/PHPStanish.php', false];
    }

    #[DataProvider('excludedPathProvider')]
    public function testRebaseExcludesDevelopmentOnlyDirectories(string $relative, bool $excluded): void
    {
        $generator = (new \ReflectionClass(NamespaceAliasGenerator::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(NamespaceAliasGenerator::class, 'isExcludedPath');
        $directory = '/app/vendor/webong/fluent';

        self::assertSame(
            $excluded,
            $method->invoke($generator, $directory . '/' . $relative, $directory),
            sprintf('Expected %s to be %s.', $relative, $excluded ? 'excluded' : 'retained'),
        );
    }
}
