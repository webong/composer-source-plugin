<?php

declare(strict_types=1);

namespace Webong\ComposerSource\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Webong\ComposerSource\SurfaceConfiguration;

final class SurfaceConfigurationTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/surfaces-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/composer.json');
        @rmdir($this->root);
    }

    public function testItParsesSurfaceDefinitions(): void
    {
        $surfaces = SurfaceConfiguration::parse([
            'core' => ['include' => ['composer.json', 'src/Core/**']],
            'javascript' => ['include' => ['src/JavaScript/**']],
        ]);

        self::assertSame(['core', 'javascript'], array_keys($surfaces));
        self::assertSame(['composer.json', 'src/Core/**'], $surfaces['core']->include);
        self::assertSame('core', $surfaces['core']->name);
    }

    public function testItReadsSurfacesFromTheInstalledPackageRoot(): void
    {
        file_put_contents($this->root . '/composer.json', json_encode([
            'name' => 'acme/monolith',
            'extra' => ['source-plugin' => ['surfaces' => [
                'core' => ['include' => ['src/Core/**']],
            ]]],
        ], JSON_THROW_ON_ERROR));

        $surfaces = SurfaceConfiguration::fromPackageRoot($this->root);

        self::assertArrayHasKey('core', $surfaces);
    }

    public function testAPackageWithoutSurfacesReturnsAnEmptyMap(): void
    {
        file_put_contents($this->root . '/composer.json', '{"name":"acme/monolith"}');

        self::assertSame([], SurfaceConfiguration::fromPackageRoot($this->root));
    }

    public function testSurfaceIncludeListsCannotBeEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one include path');

        SurfaceConfiguration::parse(['core' => ['include' => []]]);
    }

    public function testSurfaceIncludesMustBeAList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('list of include paths');

        SurfaceConfiguration::parse(['core' => ['include' => ['src/Core/**' => true]]]);
    }

    public function testUnknownSurfaceKeysAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported key');

        SurfaceConfiguration::parse(['core' => ['include' => ['src/Core/**'], 'exclude' => ['src/Core/Tests']]]);
    }

    public function testSurfaceNamesAreValidated(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('valid surface name');

        SurfaceConfiguration::parse(['../core' => ['include' => ['src/Core/**']]]);
    }

    public function testItRejectsAbsoluteIncludePaths(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('absolute');

        SurfaceConfiguration::parse(['core' => ['include' => ['/etc/passwd']]]);
    }

    public function testItRejectsPathTraversal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must stay inside the package');

        SurfaceConfiguration::parse(['core' => ['include' => ['../secret']]]);
    }

    public function testItRejectsEmptyIncludePaths(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('empty include path');

        SurfaceConfiguration::parse(['core' => ['include' => ['  ']]]);
    }

    public function testItRejectsMalformedGlobSyntax(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unterminated character class');

        SurfaceConfiguration::parse(['core' => ['include' => ['src/[Core/**']]]);
    }

    public function testDigestIgnoresIncludeOrderAndDuplicates(): void
    {
        $first = SurfaceConfiguration::parse([
            'core' => ['include' => ['src/Core/**', 'composer.json', 'src/Core/**']],
        ]);
        $second = SurfaceConfiguration::parse([
            'core' => ['include' => ['composer.json', 'src/Core/**']],
        ]);

        self::assertSame($first['core']->digest(), $second['core']->digest());
    }

    public function testItNormalizesLeadingDotSegmentsAndRepeatedSlashes(): void
    {
        $surfaces = SurfaceConfiguration::parse([
            'core' => ['include' => ['./src//Core///**']],
        ]);

        self::assertSame(['src/Core/**'], $surfaces['core']->include);
    }

    public function testItRejectsBackslashPaths(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('forward slashes');

        SurfaceConfiguration::parse(['core' => ['include' => ['src\\Core\\**']]]);
    }
}
