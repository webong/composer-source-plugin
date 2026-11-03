<?php

declare(strict_types=1);

namespace Webong\ComposerSource\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Webong\ComposerSource\WorkspaceConfiguration;

final class WorkspaceConfigurationTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/source-workspaces-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testItParsesALiteralWorkspace(): void
    {
        $this->package('packages/core', [
            'name' => 'acme/core',
            'version' => '2.1.0',
            'require' => ['php' => '>=8.1', 'acme/util' => '^1.0'],
        ]);

        $workspaces = WorkspaceConfiguration::parse(['include' => ['packages/core']], $this->root);

        self::assertArrayHasKey('acme/core', $workspaces);
        $core = $workspaces['acme/core'];
        self::assertSame('packages/core', $core->path);
        self::assertSame('2.1.0', $core->version);
        self::assertSame('^1.0', $core->requires['acme/util']);
        self::assertTrue($core->symlink);
    }

    public function testItExpandsGlobsAndSortsPackages(): void
    {
        $this->package('packages/zeta', ['name' => 'acme/zeta', 'version' => '1.0.0']);
        $this->package('packages/alpha', ['name' => 'acme/alpha', 'version' => '1.0.0']);

        $workspaces = WorkspaceConfiguration::parse(['packages/*'], $this->root);

        self::assertSame(['acme/alpha', 'acme/zeta'], array_keys($workspaces));
    }

    public function testItSupportsATrailingDoubleStar(): void
    {
        $this->package('packages/group/core', ['name' => 'acme/core', 'version' => '1.0.0']);

        $workspaces = WorkspaceConfiguration::parse(['packages/**'], $this->root);

        self::assertArrayHasKey('acme/core', $workspaces);
        self::assertSame('packages/group/core', $workspaces['acme/core']->path);
    }

    public function testItAppliesExcludesAndTheSymlinkOption(): void
    {
        $this->package('packages/core', ['name' => 'acme/core', 'version' => '1.0.0']);
        $this->package('packages/legacy', ['name' => 'acme/legacy', 'version' => '1.0.0']);

        $workspaces = WorkspaceConfiguration::parse([
            'include' => ['packages/*'],
            'exclude' => ['packages/leg*'],
            'symlink' => false,
        ], $this->root);

        self::assertSame(['acme/core'], array_keys($workspaces));
        self::assertFalse($workspaces['acme/core']->symlink);
    }

    public function testItSkipsMatchedDirectoriesWithoutAManifest(): void
    {
        $this->package('packages/core', ['name' => 'acme/core', 'version' => '1.0.0']);
        mkdir($this->root . '/packages/docs', 0777, true);

        $workspaces = WorkspaceConfiguration::parse(['packages/*'], $this->root);

        self::assertSame(['acme/core'], array_keys($workspaces));
    }

    public function testItSkipsVendorAndHiddenDirectories(): void
    {
        $this->package('vendor/acme/core', ['name' => 'acme/core', 'version' => '1.0.0']);
        $this->package('.cache/acme/core', ['name' => 'acme/cache', 'version' => '1.0.0']);
        $this->package('packages/core', ['name' => 'acme/real', 'version' => '1.0.0']);

        $workspaces = WorkspaceConfiguration::parse(['*/*', '*/**'], $this->root);

        self::assertSame(['acme/real'], array_keys($workspaces));
    }

    public function testItRejectsAnAbsolutePattern(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be package-relative');

        WorkspaceConfiguration::parse(['/tmp/packages/*'], $this->root);
    }

    public function testItRejectsTraversal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be package-relative');

        WorkspaceConfiguration::parse(['../outside'], $this->root);
    }

    public function testItRejectsAMissingLiteralDirectory(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        WorkspaceConfiguration::parse(['packages/missing'], $this->root);
    }

    public function testItRejectsALiteralDirectoryWithoutAManifest(): void
    {
        mkdir($this->root . '/packages/empty', 0777, true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has no composer.json');

        WorkspaceConfiguration::parse(['packages/empty'], $this->root);
    }

    public function testAnEmptyGlobMatchProducesNoWorkspaces(): void
    {
        self::assertSame([], WorkspaceConfiguration::parse(['packages/*'], $this->root));
    }

    public function testItRejectsDuplicatePackageNames(): void
    {
        $this->package('packages/a', ['name' => 'acme/core', 'version' => '1.0.0']);
        $this->package('packages/b', ['name' => 'acme/core', 'version' => '1.0.0']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is declared by both');

        WorkspaceConfiguration::parse(['packages/*'], $this->root);
    }

    public function testItRejectsAnInvalidPackageName(): void
    {
        $this->package('packages/core', ['name' => 'Core', 'version' => '1.0.0']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('valid package name');

        WorkspaceConfiguration::parse(['packages/*'], $this->root);
    }

    public function testItRejectsAnInvalidVersion(): void
    {
        $this->package('packages/core', ['name' => 'acme/core', 'version' => 'not a version']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid version');

        WorkspaceConfiguration::parse(['packages/*'], $this->root);
    }

    public function testItRejectsAnUnsupportedConfigurationKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported key [mode]');

        WorkspaceConfiguration::parse(['include' => ['packages/*'], 'mode' => 'inline'], $this->root);
    }

    public function testItAcceptsADevelopmentConstraintWithoutAVersion(): void
    {
        $this->package('packages/core', ['name' => 'acme/core']);

        $workspaces = WorkspaceConfiguration::parse(['packages/*'], $this->root);

        WorkspaceConfiguration::assertSatisfiable(['acme/core' => '@dev'], $workspaces);
        WorkspaceConfiguration::assertSatisfiable(['acme/core' => '*'], $workspaces);

        self::assertNull($workspaces['acme/core']->version);
    }

    public function testItRequiresAVersionForASemverConstraint(): void
    {
        $this->package('packages/core', ['name' => 'acme/core']);
        $workspaces = WorkspaceConfiguration::parse(['packages/*'], $this->root);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not declare a version');

        WorkspaceConfiguration::assertSatisfiable(['acme/core' => '^2.0'], $workspaces);
    }

    public function testItRejectsARootConstraintTheWorkspaceCannotSatisfy(): void
    {
        $this->package('packages/core', ['name' => 'acme/core', 'version' => '3.1.0']);
        $workspaces = WorkspaceConfiguration::parse(['packages/*'], $this->root);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Workspace [acme/core] is 3.1.0, but the root requires ^2.0');

        WorkspaceConfiguration::assertSatisfiable(['acme/core' => '^2.0'], $workspaces);
    }

    public function testItAcceptsASatisfiedConstraint(): void
    {
        $this->package('packages/core', ['name' => 'acme/core', 'version' => '2.4.1']);
        $workspaces = WorkspaceConfiguration::parse(['packages/*'], $this->root);

        WorkspaceConfiguration::assertSatisfiable(['acme/core' => '^2.0'], $workspaces);

        self::assertSame('2.4.1', $workspaces['acme/core']->version);
    }

    public function testProblemsReportsEveryMismatchInsteadOfStoppingAtTheFirst(): void
    {
        $this->package('packages/core', [
            'name' => 'acme/core',
            'version' => '2.0.0',
            'require' => ['acme/util' => '^5.0'],
        ]);
        $this->package('packages/util', ['name' => 'acme/util', 'version' => '1.2.0']);

        $workspaces = WorkspaceConfiguration::parse(['packages/*'], $this->root);
        $problems = WorkspaceConfiguration::problems(['acme/core' => '^9.0'], $workspaces);

        self::assertCount(2, $problems);
        self::assertStringContainsString('the root requires ^9.0', $problems[0]);
        self::assertStringContainsString('acme/core requires ^5.0', $problems[1]);
    }

    public function testItValidatesSiblingRequirementsBetweenWorkspaces(): void
    {
        $this->package('packages/core', [
            'name' => 'acme/core',
            'version' => '1.0.0',
            'require' => ['acme/util' => '^5.0'],
        ]);
        $this->package('packages/util', ['name' => 'acme/util', 'version' => '1.2.0']);

        $workspaces = WorkspaceConfiguration::parse(['packages/*'], $this->root);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('acme/core requires ^5.0');

        WorkspaceConfiguration::assertSatisfiable([], $workspaces);
    }

    public function testItIgnoresRequirementsThatAreNotWorkspaces(): void
    {
        $this->package('packages/core', [
            'name' => 'acme/core',
            'version' => '1.0.0',
            'require' => ['psr/log' => '^3.0', 'ext-json' => '*'],
        ]);
        $workspaces = WorkspaceConfiguration::parse(['packages/*'], $this->root);

        WorkspaceConfiguration::assertSatisfiable(['acme/core' => '^1.0', 'psr/log' => '^4.0'], $workspaces);

        self::assertSame('1.0.0', $workspaces['acme/core']->version);
    }

    public function testLoaderConfigurationDescribesEachWorkspace(): void
    {
        $this->package('packages/core', ['name' => 'acme/core', 'version' => '1.0.0']);
        $workspaces = WorkspaceConfiguration::parse(['packages/*'], $this->root);

        self::assertSame(
            ['acme/core' => ['type' => 'inline', 'path' => 'packages/core']],
            WorkspaceConfiguration::loaderConfiguration($workspaces),
        );
    }

    public function testAnOutlineOverrideDeactivatesAWorkspace(): void
    {
        $this->package('packages/core', ['name' => 'acme/core', 'version' => '2.0.0']);
        $workspaces = WorkspaceConfiguration::parse(['packages/*'], $this->root);

        self::assertSame(
            [],
            WorkspaceConfiguration::active($workspaces, ['acme/core' => ['type' => 'outline']]),
        );
        self::assertSame(
            ['acme/core'],
            array_keys(WorkspaceConfiguration::active($workspaces, ['acme/core' => ['type' => 'inline']])),
        );
    }

    /** @param array<string, mixed> $manifest */
    private function package(string $relative, array $manifest): void
    {
        $directory = $this->root . '/' . $relative;
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents(
            $directory . '/composer.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    private function remove(string $path): void
    {
        if (! is_dir($path)) {
            @unlink($path);

            return;
        }

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->remove($path . '/' . $entry);
        }

        @rmdir($path);
    }
}
