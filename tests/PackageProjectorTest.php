<?php

declare(strict_types=1);

namespace Webong\ComposerSource\Tests;

use PHPUnit\Framework\TestCase;
use Webong\ComposerSource\PackageProjector;
use Webong\ComposerSource\ProjectionMarker;
use Webong\ComposerSource\ProjectionPlan;
use Webong\ComposerSource\ProjectionPlanner;
use Webong\ComposerSource\SurfaceConfiguration;

final class PackageProjectorTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/projector-' . bin2hex(random_bytes(6));
        $this->file('composer.json', '{"name":"acme/monolith"}');
        $this->file('LICENSE', 'MIT');
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

    public function testSelectedFilesSurviveAndUnselectedFilesDisappear(): void
    {
        $result = $this->projectorFor(['core', 'javascript'])->project($this->plan(['core', 'javascript']));

        self::assertTrue($result->changed);
        self::assertFileExists($this->root . '/composer.json');
        self::assertFileExists($this->root . '/src/Core/Thing.php');
        self::assertFileExists($this->root . '/src/Core/Nested/Deep.php');
        self::assertFileExists($this->root . '/src/JavaScript/Widget.php');
        self::assertFileExists($this->root . '/resources/javascript/app.js');
        self::assertFileDoesNotExist($this->root . '/src/Lua/Thing.php');
        self::assertFileDoesNotExist($this->root . '/resources/lua/init.lua');
        self::assertFileDoesNotExist($this->root . '/docs/lua/guide.md');
        self::assertFileDoesNotExist($this->root . '/README.md');
        self::assertDirectoryDoesNotExist($this->root . '/docs');
        self::assertDirectoryDoesNotExist($this->root . '/src/Lua');
    }

    public function testItWritesAProjectionMarker(): void
    {
        $this->projectorFor(['core'])->project($this->plan(['core']));

        $marker = ProjectionMarker::read($this->root);

        self::assertNotNull($marker);
        self::assertSame('acme/monolith', $marker['package']);
        self::assertSame(['core'], $marker['surfaces']);
        self::assertContains('src/Lua/Thing.php', $marker['removed']);
    }

    public function testItPreservesExecutablePermissions(): void
    {
        $script = $this->root . '/src/Core/run.sh';
        file_put_contents($script, "#!/bin/sh\n");
        chmod($script, 0755);

        $this->projectorFor(['core'])->project($this->plan(['core']));

        clearstatcache();
        self::assertSame(0755, fileperms($this->root . '/src/Core/run.sh') & 0777);
    }

    public function testRerunningTheSameProjectionIsIdempotent(): void
    {
        $this->projectorFor(['core'])->project($this->plan(['core']));

        $before = filemtime($this->root . '/src/Core/Thing.php');
        $markerBefore = (string) file_get_contents(ProjectionMarker::path($this->root));
        clearstatcache();

        $result = $this->projectorFor(['core'])->project(
            $this->plan(['core'], ProjectionMarker::read($this->root), false),
        );

        self::assertFalse($result->changed);
        clearstatcache();
        self::assertSame($before, filemtime($this->root . '/src/Core/Thing.php'));
        self::assertSame($markerBefore, (string) file_get_contents(ProjectionMarker::path($this->root)));
    }

    public function testNarrowingRemovesAdditionalFiles(): void
    {
        $this->projectorFor(['core', 'javascript'])->project($this->plan(['core', 'javascript']));

        self::assertFileExists($this->root . '/src/JavaScript/Widget.php');

        $this->projectorFor(['core'])->project($this->plan(['core'], ProjectionMarker::read($this->root), false));

        self::assertFileDoesNotExist($this->root . '/src/JavaScript/Widget.php');
        self::assertFileExists($this->root . '/src/Core/Thing.php');
    }

    public function testItPreservesGitMetadata(): void
    {
        $this->file('.git/HEAD', 'ref: refs/heads/main');
        $this->file('.git/objects/aa/bb', 'object');

        $this->projectorFor(['core'])->project($this->plan(['core']));

        self::assertFileExists($this->root . '/.git/HEAD');
        self::assertFileExists($this->root . '/.git/objects/aa/bb');
    }

    public function testItNeverDeletesThroughASymlink(): void
    {
        $outside = sys_get_temp_dir() . '/outside-' . bin2hex(random_bytes(6));
        mkdir($outside, 0777, true);
        file_put_contents($outside . '/keep.txt', 'keep');
        symlink($outside, $this->root . '/src/Lua/escape');

        $planner = $this->planner(['core']);
        try {
            $plan = $planner->plan(['core'], null, true);
            $this->projectorFor(['core'])->project($plan);
            self::fail('A symlink escape must abort before mutation.');
        } catch (\InvalidArgumentException) {
            self::assertFileExists($outside . '/keep.txt');
            self::assertFileExists($this->root . '/src/Lua/Thing.php');
        } finally {
            $this->remove($outside);
        }
    }

    /** @param list<string> $surfaces */
    private function projectorFor(array $surfaces): PackageProjector
    {
        return new PackageProjector($this->root);
    }

    /** @param list<string> $surfaces */
    private function planner(array $surfaces): ProjectionPlanner
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

    /** @param list<string> $surfaces */
    private function plan(array $surfaces, ?array $marker = null, bool $fresh = true): ProjectionPlan
    {
        return $this->planner($surfaces)->plan($surfaces, $marker, $fresh);
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
