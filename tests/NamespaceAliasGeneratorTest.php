<?php

declare(strict_types=1);

namespace Webong\ComposerSource\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webong\ComposerSource\NamespaceAliasDefinition;
use Webong\ComposerSource\NamespaceAliasGenerator;

final class NamespaceAliasGeneratorTest extends TestCase
{
    public function testAutoloadCopyKeepsLayoutAndExplicitResourcesWithoutDevelopmentFiles(): void
    {
        $root = sys_get_temp_dir() . '/rebase-selection-' . bin2hex(random_bytes(6));
        foreach (['src/Tests', 'resources', '.github'] as $directory) {
            mkdir($root . '/source/' . $directory, 0777, true);
        }
        foreach (['src/Thing.php', 'src/Tests/Test.php', 'resources/view.php', 'composer.json', '.github/ci.yml'] as $file) {
            file_put_contents($root . '/source/' . $file, '<?php namespace Acme;');
        }
        $generator = (new \ReflectionClass(NamespaceAliasGenerator::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(NamespaceAliasGenerator::class, 'rebasePackageFiles');
        try {
            $method->invoke($generator, $root . '/source', $root . '/target', new NamespaceAliasDefinition('Acme\\', 'Local\\', 'rebase'));
            self::assertFileExists($root . '/target/composer.json');
            $method->invoke($generator, $root . '/source', $root . '/target',
                new NamespaceAliasDefinition('Acme\\', 'Local\\', 'rebase'),
                ['src', 'resources'], ['src/Tests']);
            self::assertFileExists($root . '/target/src/Thing.php');
            self::assertFileExists($root . '/target/resources/view.php');
            self::assertFileDoesNotExist($root . '/target/composer.json');
            self::assertFileDoesNotExist($root . '/target/.github/ci.yml');
            self::assertFileDoesNotExist($root . '/target/src/Tests/Test.php');
            self::assertStringContainsString('namespace Local', file_get_contents($root . '/target/src/Thing.php'));
        } finally {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($root);
        }
    }

    public function testPackageMetadataUsesTheExpectedNamespaceAliasShape(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('webong/composer-source-plugin', $composer['name']);
        self::assertSame(
            'Webong\\ComposerSource\\ComposerSourcePlugin',
            $composer['extra']['class'],
        );
    }

    public function testGeneratedAliasesAreLoadedThroughThePluginFilesAutoloadEntry(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true, flags: JSON_THROW_ON_ERROR);

        // Composer omits the files-loading section of the generated autoloader
        // unless some package declares a "files" autoload entry, so the plugin
        // must declare its own or the generated files are never loaded.
        self::assertSame(['src/bootstrap.php'], $composer['autoload']['files']);

        $bootstrap = (string) file_get_contents(__DIR__ . '/../src/bootstrap.php');
        self::assertStringContainsString('namespace_aliases.php', $bootstrap);
        self::assertStringContainsString('namespace_rebases.php', $bootstrap);
    }

    public function testTheBootstrapFindsTheComposerDirectoryFromAnyInstallDepth(): void
    {
        $bootstrap = (string) file_get_contents(__DIR__ . '/../src/bootstrap.php');

        // It must not assume a fixed vendor/<vendor>/<package>/src depth, or it
        // breaks for path repositories and non-standard vendor dirs.
        self::assertStringContainsString('dirname(', $bootstrap);
        self::assertStringNotContainsString("dirname(__DIR__, 3)", $bootstrap);
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

    public function testRebaseRequiresRebasedAutoloadFiles(): void
    {
        $generator = (new \ReflectionClass(NamespaceAliasGenerator::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(NamespaceAliasGenerator::class, 'writeRebaseAutoloadFile');
        $file = tempnam(sys_get_temp_dir(), 'rebase') . '.php';

        $method->invoke($generator, $file, [
            [
                'source' => 'Webong\Fluent\FluentServiceProvider',
                'target' => 'Zorvia\Fluent\FluentServiceProvider',
                'target_prefix' => 'Zorvia\\Fluent\\',
                'kind' => 'class',
                'paths' => ['rebased/webong--fluent/src'],
                'files' => ['rebased/webong--fluent/src/helpers.php'],
            ],
        ]);

        $contents = (string) file_get_contents($file);
        unlink($file);

        self::assertStringContainsString("require_once __DIR__ . '/rebased/webong--fluent/src/helpers.php';", $contents);
        self::assertLessThan(
            strpos($contents, 'class_alias'),
            strpos($contents, 'require_once'),
            'Autoload files must be required before the class aliases are declared.',
        );
    }

    public function testRebaseDeduplicatesAutoloadFilesSharedBySeveralRebases(): void
    {
        $generator = (new \ReflectionClass(NamespaceAliasGenerator::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(NamespaceAliasGenerator::class, 'writeRebaseAutoloadFile');
        $file = tempnam(sys_get_temp_dir(), 'rebase') . '.php';

        $rebase = static fn (string $source, string $target): array => [
            'source' => $source,
            'target' => $target,
            'target_prefix' => 'Zorvia\\Fluent\\',
            'kind' => 'class',
            'paths' => ['rebased/webong--fluent/src'],
            'files' => ['rebased/webong--fluent/src/helpers.php'],
        ];

        $method->invoke($generator, $file, [
            $rebase('Webong\Fluent\A', 'Zorvia\Fluent\A'),
            $rebase('Webong\Fluent\B', 'Zorvia\Fluent\B'),
        ]);

        $contents = (string) file_get_contents($file);
        unlink($file);

        self::assertSame(1, substr_count($contents, 'require_once'));
    }

    public function testRebaseSkipsAutoloadFilesThatDoNotDeclareTheSourceNamespace(): void
    {
        $root = sys_get_temp_dir() . '/rebase-files-' . bin2hex(random_bytes(4));
        mkdir($root . '/src', 0777, true);

        file_put_contents($root . '/src/namespaced.php', "<?php\n\nnamespace Webong\\Fluent;\n\nfunction namespaced_helper(): void {}\n");
        file_put_contents($root . '/src/global.php', "<?php\n\nfunction global_helper(): void {}\n");

        $generator = (new \ReflectionClass(NamespaceAliasGenerator::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(NamespaceAliasGenerator::class, 'rebasedAutoloadFiles');
        $definition = new NamespaceAliasDefinition('Webong\\Fluent\\', 'Zorvia\\Fluent\\', NamespaceAliasDefinition::TYPE_REBASE);

        $result = $method->invoke(
            $generator,
            ['src/namespaced.php', 'src/global.php', 'src/missing.php', 42, ''],
            $root,
            'rebased/webong--fluent',
            $definition,
        );

        unlink($root . '/src/namespaced.php');
        unlink($root . '/src/global.php');
        rmdir($root . '/src');
        rmdir($root);

        self::assertSame(['rebased/webong--fluent/src/namespaced.php'], $result);
    }
}
