<?php

declare(strict_types=1);

namespace Webong\ComposerSource\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Webong\ComposerSource\ProjectionConfiguration;
use Webong\ComposerSource\ProjectionDefinition;

final class ProjectionConfigurationTest extends TestCase
{
    public function testItParsesAndNormalizesSelectedSurfaces(): void
    {
        $definitions = ProjectionConfiguration::parse([
            'acme/monolith' => ['surfaces' => ['javascript', 'core', 'core']],
        ]);

        self::assertCount(1, $definitions);
        self::assertSame('acme/monolith', $definitions['acme/monolith']->package);
        self::assertSame(['core', 'javascript'], $definitions['acme/monolith']->surfaces);
    }

    public function testAnEmptyConfigurationProducesNoProjections(): void
    {
        self::assertSame([], ProjectionConfiguration::parse([]));
    }

    public function testAtLeastOneSurfaceMustBeSelected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one surface');

        ProjectionConfiguration::parse(['acme/monolith' => ['surfaces' => []]]);
    }

    public function testSurfacesMustBeAListOfNames(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('list of surface names');

        ProjectionConfiguration::parse(['acme/monolith' => ['surfaces' => ['core' => true]]]);
    }

    public function testSurfaceNamesCannotBeEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty surface name');

        ProjectionConfiguration::parse(['acme/monolith' => ['surfaces' => ['  ']]]);
    }

    public function testUnknownConfigurationKeysAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported key');

        ProjectionConfiguration::parse(['acme/monolith' => ['surfaces' => ['core'], 'raw' => ['**']]]);
    }

    public function testItRejectsProjectingThePluginItself(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot project the source plugin itself');

        ProjectionConfiguration::parse([
            'webong/composer-source-plugin' => ['surfaces' => ['core']],
        ]);
    }

    public function testItRejectsAPackageThatIsAlsoMirrored(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('both projected and mirrored');

        ProjectionConfiguration::parse(
            ['acme/monolith' => ['surfaces' => ['core']]],
            ['acme/monolith' => ['path' => 'ext/monolith']],
        );
    }

    public function testItRejectsAPackageThatIsAlsoAConfiguredLoader(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('both projected and loadable');

        ProjectionConfiguration::parse(
            ['acme/monolith' => ['surfaces' => ['core']]],
            [],
            ['acme/monolith' => ['type' => 'inline']],
        );
    }

    public function testItAllowsDifferentPackagesForEachFeature(): void
    {
        $definitions = ProjectionConfiguration::parse(
            ['acme/monolith' => ['surfaces' => ['core']]],
            ['acme/other' => ['path' => 'ext/other']],
            ['acme/third' => ['type' => 'outline']],
        );

        self::assertCount(1, $definitions);
        self::assertSame(ProjectionDefinition::class, $definitions['acme/monolith']::class);
    }

    public function testPackageNamesMustBeComposerPackageNames(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('valid Composer package name');

        ProjectionConfiguration::parse(['acme' => ['surfaces' => ['core']]]);
    }
}
