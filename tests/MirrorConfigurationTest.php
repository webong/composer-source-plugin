<?php

declare(strict_types=1);

namespace Webong\ComposerSource\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Webong\ComposerSource\MirrorConfiguration;
use Webong\ComposerSource\MirrorDefinition;

final class MirrorConfigurationTest extends TestCase
{
    public function testItParsesAPathAndDefaultsTheOriginToRemote(): void
    {
        $mirrors = MirrorConfiguration::parse(['acme/fluent' => ['path' => 'ext/fluent']]);

        self::assertCount(1, $mirrors);
        self::assertSame('acme/fluent', $mirrors[0]->package);
        self::assertSame('ext/fluent', $mirrors[0]->path);
        self::assertSame(MirrorDefinition::ORIGIN_REMOTE, $mirrors[0]->origin);
    }

    public function testItAcceptsABareStringAsThePath(): void
    {
        $mirrors = MirrorConfiguration::parse(['acme/fluent' => 'ext/fluent']);

        self::assertSame('ext/fluent', $mirrors[0]->path);
    }

    public function testItRejectsAPackageThatIsAlsoConfiguredAsALoader(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be both mirrored and loadable');

        MirrorConfiguration::parse(
            ['acme/fluent' => ['path' => 'ext/fluent']],
            ['acme/fluent' => ['type' => 'inline']],
        );
    }

    public function testItAllowsMirroringAndLoadingDifferentPackages(): void
    {
        $mirrors = MirrorConfiguration::parse(
            ['acme/fluent' => ['path' => 'ext/fluent']],
            ['acme/other' => ['type' => 'inline']],
        );

        self::assertCount(1, $mirrors);
    }

    public function testItRejectsAMissingPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must declare a string path');

        MirrorConfiguration::parse(['acme/fluent' => ['type' => 'remote']]);
    }

    public function testItRejectsAnEmptyPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must declare a path');

        MirrorConfiguration::parse(['acme/fluent' => ['path' => '  ']]);
    }

    public function testItRejectsAnUnsupportedOrigin(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported origin');

        MirrorConfiguration::parse(['acme/fluent' => ['path' => 'ext/fluent', 'origin' => 'local']]);
    }

    public function testItRejectsANonArrayConfiguration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must map to a path or a configuration array');

        MirrorConfiguration::parse(['acme/fluent' => 42]);
    }

    public function testAnEmptyConfigurationProducesNoMirrors(): void
    {
        self::assertSame([], MirrorConfiguration::parse([]));
        self::assertSame([], MirrorConfiguration::indexed([]));
    }

    public function testIndexedKeysByPackageName(): void
    {
        $indexed = MirrorConfiguration::indexed([
            'acme/fluent' => ['path' => 'ext/fluent'],
            'acme/other' => ['path' => 'ext/other'],
        ]);

        self::assertSame(['acme/fluent', 'acme/other'], array_keys($indexed));
    }
}
