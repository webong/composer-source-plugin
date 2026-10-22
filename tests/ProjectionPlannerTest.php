<?php

declare(strict_types=1);

namespace Webong\ComposerSource\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Webong\ComposerSource\ProjectionMarker;
use Webong\ComposerSource\ProjectionPlanner;
use Webong\ComposerSource\SurfaceConfiguration;

final class ProjectionPlannerTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/planner-' . bin2hex(random_bytes(6));
        $this->file('composer.json', '{"name":"acme/monolith"}');
        $this->file('LICENSE', 'MIT');
        $this->file('README.md', '# monolith');
        $this->file('src/Core/Thing.php', '<?php');
        $this->file('src/Core/Nested/Deep.php', '<?php');
        $this->file('src/JavaScript/Widget.php', '<?php');
        $this->file('resources/javascript/app.js', 'js');
        $this->file('src/Lua/Thing.php', '<?php');
        $this->file('resources/lua/init.lua', 'lua');
        $this->file('docs/lua/guide.md', 'docs');
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testItBuildsADeterministicAllowlist(): void
    {
        $plan = $this->planner()->plan(['javascript', 'core', 'core'], null, true);

        self::assertSame(['core', 'javascript'], $plan->surfaces);
        self::assertSame($this->sort([
            'composer.json',
            'src/Core/**',
            'src/JavaScript/**',
            'resources/javascript/**',
        ]), $this->sort($plan->patterns));

        self::assertSame($this->sort([
            'composer.json',
            'src/Core/Nested/Deep.php',
            'src/Core/Thing.php',
            'src/JavaScript/Widget.php',
            'resources/javascript/app.js',
        ]), $this->sort($plan->keep));

        self::assertContains('src/Lua/Thing.php', $plan->remove);
        self::assertNotContains('README.md', $plan->keep);
        self::assertSame($plan->keep, $this->sort($plan->keep));
        self::assertSame($plan->patterns, $this->sort($plan->patterns));
    }

    public function testComposerJsonIsAlwaysRetained(): void
    {
        $plan = $this->planner()->plan(['javascript'], null, true);

        self::assertContains('composer.json', $plan->keep);
        self::assertContains('composer.json', $plan->patterns);
    }

    public function testItRejectsAnUnknownSelectedSurface(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown surface [native]');

        $this->planner()->plan(['core', 'native'], null, true);
    }

    public function testANarrowingSelectionMayRemoveMoreFiles(): void
    {
        $marker = ProjectionMarker::create(
            'acme/monolith',
            'ref',
            '1.0.0',
            ['core', 'javascript'],
            ['composer.json', 'src/Core/**', 'src/JavaScript/**', 'resources/javascript/**'],
            'sha',
            'sha',
            ['src/Lua/Thing.php'],
        );

        $plan = $this->planner()->plan(['core'], $marker, false);

        self::assertSame(['core'], $plan->surfaces);
        self::assertContains('src/JavaScript/Widget.php', $plan->remove);
        self::assertNotContains('src/JavaScript/Widget.php', $plan->keep);
    }

    public function testWideningWithoutAFreshInstallIsActionable(): void
    {
        $marker = ProjectionMarker::create(
            'acme/monolith',
            'ref',
            '1.0.0',
            ['core'],
            ['composer.json', 'src/Core/**'],
            'sha',
            'sha',
            ['src/JavaScript/Widget.php', 'resources/javascript/app.js'],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('composer reinstall acme/monolith');

        // Simulate the tree after the earlier projection.
        unlink($this->root . '/src/JavaScript/Widget.php');
        unlink($this->root . '/resources/javascript/app.js');

        $this->planner()->plan(['core', 'javascript'], $marker, false);
    }

    public function testWideningAfterAFreshInstallIsAllowed(): void
    {
        $marker = ProjectionMarker::create(
            'acme/monolith',
            'ref',
            '1.0.0',
            ['core'],
            ['composer.json', 'src/Core/**'],
            'sha',
            'sha',
            ['src/JavaScript/Widget.php'],
        );

        $plan = $this->planner()->plan(['core', 'javascript'], $marker, true);

        self::assertContains('src/JavaScript/Widget.php', $plan->keep);
    }

    public function testTheRemovedHistorySurvivesRepeatedProjections(): void
    {
        $marker = ProjectionMarker::create(
            'acme/monolith',
            'ref',
            '1.0.0',
            ['core'],
            ['composer.json', 'src/Core/**'],
            'sha',
            'sha',
            ['src/Lua/Thing.php'],
        );

        $plan = $this->planner()->plan(['core'], $marker, false);

        self::assertContains('src/Lua/Thing.php', $plan->marker['removed']);
        self::assertContains('src/JavaScript/Widget.php', $plan->marker['removed']);
    }

    public function testAFreshInstallDiscardsStaleRemovalHistory(): void
    {
        $marker = ProjectionMarker::create(
            'acme/monolith',
            'ref',
            '1.0.0',
            ['core'],
            ['composer.json', 'src/Core/**'],
            'sha',
            'sha',
            ['src/RemovedInAnOlderRelease.php'],
        );

        $plan = $this->planner()->plan(['core'], $marker, true);

        self::assertNotContains('src/RemovedInAnOlderRelease.php', $plan->marker['removed']);
        self::assertContains('src/JavaScript/Widget.php', $plan->marker['removed']);
    }

    public function testItRejectsSymlinksThatEscapeThePackageRoot(): void
    {
        $outside = sys_get_temp_dir() . '/outside-' . bin2hex(random_bytes(6));
        mkdir($outside, 0777, true);
        file_put_contents($outside . '/secret.txt', 'secret');
        symlink($outside . '/secret.txt', $this->root . '/src/Core/Secret.php');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outside the package root');

        $this->planner()->plan(['core'], null, true);
    }

    public function testItIgnoresGitMetadata(): void
    {
        $this->file('.git/HEAD', 'ref: refs/heads/main');
        $this->file('.git/config', '[core]');

        $plan = $this->planner()->plan(['core'], null, true);

        self::assertNotContains('.git/HEAD', $plan->keep);
        self::assertNotContains('.git/HEAD', $plan->remove);
    }

    private function planner(): ProjectionPlanner
    {
        return new ProjectionPlanner(
            'acme/monolith',
            $this->root,
            SurfaceConfiguration::parse([
                'core' => ['include' => ['src/Core/**']],
                'javascript' => ['include' => ['src/JavaScript/**', 'resources/javascript/**']],
                'lua' => ['include' => ['src/Lua/**', 'resources/lua/**', 'docs/lua/**']],
            ]),
        );
    }

    /** @param list<string> $values @return list<string> */
    private function sort(array $values): array
    {
        sort($values);

        return array_values(array_unique($values));
    }

    private function file(string $relative, string $contents): void
    {
        $file = $this->root . '/' . $relative;
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, $contents);
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->remove($path . '/' . $entry);
        }
        @rmdir($path);
    }
}
