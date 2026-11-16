<?php

declare(strict_types=1);

namespace Webong\ComposerSource\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Webong\ComposerSource\PackageProjector;
use Webong\ComposerSource\ProjectionPlanner;
use Webong\ComposerSource\SurfaceConfiguration;

final class SurfaceDiscoveryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/surface-discovery-'.bin2hex(random_bytes(8));
        $this->write('composer.json', ['extra' => ['source-plugin' => ['surfaces' => ['core/composer.json', 'ext/*/composer.json']]]]);
        $this->group('core', true);
        $this->group('ext/Laravel', ['extends' => 'core', 'exclude' => ['tests', 'composer.json']]);
        $this->group('ext/OpenWorkflow', true);
        $this->write('core/Thing.php', '<?php');
        $this->write('ext/Laravel/Bridge.php', '<?php');
        $this->write('ext/Laravel/tests/Test.php', '<?php');
        $this->write('ext/OpenWorkflow/Executor.php', '<?php');
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testDiscoveryDefaultsToDirectoryNamesAndResolvesInheritance(): void
    {
        $surfaces = SurfaceConfiguration::fromPackageRoot($this->root);
        self::assertSame(['core', 'laravel', 'open-workflow'], array_keys($surfaces));
        self::assertSame(['core', 'ext/Laravel'], $surfaces['laravel']->include);
        self::assertSame(['ext/Laravel/composer.json', 'ext/Laravel/tests'], $surfaces['laravel']->exclude);
    }

    public function testProjectionRetainsDescriptorsForUnselectedGroupsAndReplaysDeterministically(): void
    {
        $surfaces = SurfaceConfiguration::fromPackageRoot($this->root);
        $plan = (new ProjectionPlanner('acme/demo', $this->root, $surfaces))->plan(['laravel'], null, true);
        self::assertContains('core/Thing.php', $plan->keep);
        self::assertContains('ext/Laravel/Bridge.php', $plan->keep);
        self::assertContains('ext/Laravel/tests/Test.php', $plan->remove);
        self::assertContains('ext/OpenWorkflow/Executor.php', $plan->remove);
        self::assertContains('ext/OpenWorkflow/composer.json', $plan->keep);
        self::assertContains('ext/Laravel/composer.json', $plan->keep);
        (new PackageProjector($this->root))->project($plan);
        $after = SurfaceConfiguration::fromPackageRoot($this->root);
        self::assertSame(SurfaceConfiguration::digest($surfaces), SurfaceConfiguration::digest($after));
        $replay = (new ProjectionPlanner('acme/demo', $this->root, $after))->plan(['laravel'], $plan->marker);
        self::assertSame($plan->patternsDigest, $replay->patternsDigest);
        self::assertSame($plan->keep, $replay->keep);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Reinstall');
        (new ProjectionPlanner('acme/demo', $this->root, $after))->plan(['open-workflow'], $plan->marker);
    }

    public function testUnmarkedNestedManifestsDoNotCreateSurfaces(): void
    {
        $this->write('ext/Ordinary/composer.json', ['name' => 'acme/ordinary']);
        self::assertArrayNotHasKey('ordinary', SurfaceConfiguration::fromPackageRoot($this->root));
    }

    public function testDuplicateNamesFailBeforePruning(): void
    {
        $this->group('ext/OpenWorkflow', ['name' => 'core']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unique');
        SurfaceConfiguration::fromPackageRoot($this->root);
    }

    public function testUnknownParentsAreRejected(): void
    {
        $this->group('ext/Laravel', ['extends' => 'missing']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown parent');
        SurfaceConfiguration::fromPackageRoot($this->root);
    }

    public function testInheritanceCyclesAreRejected(): void
    {
        $this->group('core', ['extends' => 'laravel']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cycle');
        SurfaceConfiguration::fromPackageRoot($this->root);
    }

    public function testGroupPatternsCannotEscapeTheOwningDirectory(): void
    {
        $this->group('core', ['include' => ['../bin']]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must stay inside');
        SurfaceConfiguration::fromPackageRoot($this->root);
    }

    public function testSymlinkedManifestCannotBeReadEvenInsideThePackage(): void
    {
        unlink($this->root.'/ext/OpenWorkflow/composer.json');
        symlink('../../core/composer.json', $this->root.'/ext/OpenWorkflow/composer.json');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not symlinks');
        SurfaceConfiguration::fromPackageRoot($this->root);
    }

    public function testExclusionsDoNotVetoAnotherSelectedSurface(): void
    {
        $surfaces = SurfaceConfiguration::parse([
            'all' => ['include' => ['ext/Laravel'], 'exclude' => ['ext/Laravel/tests']],
            'tests' => ['ext/Laravel/tests'],
        ]);
        $plan = (new ProjectionPlanner('acme/demo', $this->root, $surfaces))->plan(['all', 'tests'], null, true);
        self::assertContains('ext/Laravel/tests/Test.php', $plan->keep);
    }

    public function testMapInheritanceAndShorthandHaveTheSameExpandedDigestAsExplicitLists(): void
    {
        $composed = SurfaceConfiguration::parse(['base' => ['core'], 'child' => ['extends' => 'base', 'include' => ['ext/Laravel']]]);
        $explicit = SurfaceConfiguration::parse(['base' => ['include' => ['core']], 'child' => ['include' => ['core', 'ext/Laravel']]]);
        self::assertSame(SurfaceConfiguration::digest($explicit), SurfaceConfiguration::digest($composed));
    }

    public function testChangingAnExclusionChangesTheDefinitionDigest(): void
    {
        $before = SurfaceConfiguration::fromPackageRoot($this->root);
        $this->group('ext/Laravel', ['extends' => 'core']);
        self::assertNotSame(SurfaceConfiguration::digest($before), SurfaceConfiguration::digest(SurfaceConfiguration::fromPackageRoot($this->root)));
    }

    public function testExplicitIncludesCanLimitTheDirectoryWithoutDeletingItsDescriptor(): void
    {
        $this->group('ext/Laravel', ['include' => ['Bridge.php']]);
        $surfaces = SurfaceConfiguration::fromPackageRoot($this->root);
        $plan = (new ProjectionPlanner('acme/demo', $this->root, $surfaces))->plan(['laravel'], null, true);
        self::assertContains('ext/Laravel/Bridge.php', $plan->keep);
        self::assertContains('ext/Laravel/tests/Test.php', $plan->remove);
        self::assertContains('ext/Laravel/composer.json', $plan->keep);
    }

    public function testMissingLiteralManifestsAreRejectedEvenIfOtherGlobsMatch(): void
    {
        unlink($this->root.'/core/composer.json');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');
        SurfaceConfiguration::fromPackageRoot($this->root);
    }

    public function testMalformedIncludeWithAParentFailsClearly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SurfaceConfiguration::parse(['base' => ['core'], 'child' => ['extends' => 'base', 'include' => 'wrong']]);
    }

    public function testLegacyDefinitionDigestRemainsCompatible(): void
    {
        self::assertSame(hash('sha256', '{"core":["src"]}'), SurfaceConfiguration::digest(SurfaceConfiguration::parse(['core' => ['include' => ['src']]])));
    }

    public function testNumericDirectoryNamesAreValidDiscoveredSurfaces(): void
    {
        $this->group('ext/123', true);
        self::assertSame('123', SurfaceConfiguration::fromPackageRoot($this->root)['123']->name);
    }

    private function group(string $directory, mixed $config): void
    {
        $this->write($directory.'/composer.json', ['extra' => ['source-plugin' => ['surface' => $config]]]);
    }

    private function write(string $path, mixed $contents): void
    {
        if (! is_dir(dirname($this->root.'/'.$path))) {
            mkdir(dirname($this->root.'/'.$path), 0700, true);
        }
        file_put_contents($this->root.'/'.$path, is_array($contents) ? json_encode($contents, JSON_THROW_ON_ERROR) : $contents);
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && ! is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path.'/'.$entry);
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }
}
