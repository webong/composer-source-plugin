<?php

declare(strict_types=1);

namespace Webong\ComposerSource\Tests;

use PHPUnit\Framework\TestCase;
use Webong\ComposerSource\SourceMirror;
use Webong\ComposerSource\NamespaceAliasDefinition;

final class SourceMirrorTest extends TestCase
{
    private string $root = '';

    private string $source = '';

    private string $mirror = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mirror-' . bin2hex(random_bytes(6));
        $this->source = $this->root . '/vendor/acme/fluent';
        $this->mirror = $this->root . '/ext/fluent';

        mkdir($this->source . '/src', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testItCopiesUpstreamSourceIntoTheMirror(): void
    {
        $this->upstream('src/Thing.php', '<?php namespace Acme;');
        $this->upstream('README.md', '# upstream');

        $result = $this->mirrorSync();

        self::assertSame(['README.md', 'src/Thing.php'], $result->written);
        self::assertSame('<?php namespace Acme;', $this->mirrorFile('src/Thing.php'));
        self::assertSame('# upstream', $this->mirrorFile('README.md'));
        self::assertTrue($result->hasChanges());
    }

    public function testItCreatesTheMirrorRootWhenMissing(): void
    {
        $this->upstream('src/Thing.php', 'x');

        self::assertDirectoryDoesNotExist($this->mirror);

        $this->mirrorSync();

        self::assertDirectoryExists($this->mirror);
    }

    public function testASecondSyncWithNoUpstreamChangeRewritesNothing(): void
    {
        $this->upstream('src/Thing.php', '<?php namespace Acme;');
        $this->mirrorSync();

        $mirrorFile = $this->mirror . '/src/Thing.php';
        $before = filemtime($mirrorFile);
        $hashBefore = hash_file('sha256', $mirrorFile);
        clearstatcache();

        $second = $this->mirrorSync();

        self::assertSame([], $second->written);
        self::assertFalse($second->hasChanges());
        self::assertSame(['src/Thing.php'], $second->unchanged);
        self::assertSame($hashBefore, hash_file('sha256', $mirrorFile));
        self::assertSame($before, filemtime($mirrorFile), 'A no-op sync must not churn mtimes.');
    }

    public function testALocalEditToAnUntouchedFileSurvives(): void
    {
        $this->upstream('src/Thing.php', 'upstream version');
        $this->upstream('src/Other.php', 'other');
        $this->mirrorSync();

        file_put_contents($this->mirror . '/src/Thing.php', 'my local edit');
        $this->upstream('src/Other.php', 'other changed upstream');

        $result = $this->mirrorSync();

        self::assertSame(['src/Other.php'], $result->written);
        self::assertSame([], $result->conflicts);
        self::assertSame('my local edit', $this->mirrorFile('src/Thing.php'));
    }

    public function testAFileChangedOnBothSidesIsReportedAndNeverOverwritten(): void
    {
        $this->upstream('src/Thing.php', 'base');
        $this->mirrorSync();

        file_put_contents($this->mirror . '/src/Thing.php', 'my local edit');
        $this->upstream('src/Thing.php', 'new upstream');

        $result = $this->mirrorSync();

        self::assertSame(['src/Thing.php'], $result->conflicts);
        self::assertSame([], $result->written);
        self::assertSame('my local edit', $this->mirrorFile('src/Thing.php'));
        self::assertSame('new upstream', $this->mirrorFile('src/Thing.php.upstream'));
    }

    public function testResolvingAConflictByTakingUpstreamClearsItOnTheNextSync(): void
    {
        $this->upstream('src/Thing.php', 'base');
        $this->mirrorSync();

        file_put_contents($this->mirror . '/src/Thing.php', 'my local edit');
        $this->upstream('src/Thing.php', 'new upstream');
        $this->mirrorSync();

        // The developer accepts upstream.
        copy($this->mirror . '/src/Thing.php.upstream', $this->mirror . '/src/Thing.php');
        $result = $this->mirrorSync();

        self::assertSame([], $result->conflicts);
        self::assertSame([], $result->written);
        self::assertSame('new upstream', $this->mirrorFile('src/Thing.php'));
    }

    public function testARemovedUpstreamFileIsDeletedOnlyWhenItIsLocallyUntouched(): void
    {
        $this->upstream('src/Gone.php', 'doomed');
        $this->upstream('src/Kept.php', 'doomed too');
        $this->mirrorSync();

        file_put_contents($this->mirror . '/src/Kept.php', 'i edited this');
        unlink($this->source . '/src/Gone.php');
        unlink($this->source . '/src/Kept.php');

        $result = $this->mirrorSync();

        self::assertSame(['src/Gone.php'], $result->removed);
        self::assertFileDoesNotExist($this->mirror . '/src/Gone.php');
        self::assertSame('i edited this', $this->mirrorFile('src/Kept.php'));
        self::assertNotEmpty($result->warnings);
    }

    public function testLocalOnlyFilesAreNeitherTrackedNorDeleted(): void
    {
        $this->upstream('src/Thing.php', 'x');
        $this->mirrorSync();

        mkdir($this->mirror . '/notes', 0777, true);
        file_put_contents($this->mirror . '/notes/mine.md', 'scratch');
        $this->mirrorSync();

        self::assertSame('scratch', $this->mirrorFile('notes/mine.md'));

        $state = json_decode((string) file_get_contents($this->mirror . '/.source-plugin/sync.json'), true);
        self::assertArrayNotHasKey('notes/mine.md', $state['files']);
    }

    public function testItNeverMirrorsTheVendorsOrTheSnapshotItself(): void
    {
        $this->upstream('src/Thing.php', 'x');
        mkdir($this->source . '/vendor/other', 0777, true);
        file_put_contents($this->source . '/vendor/other/dep.php', '<?php');
        mkdir($this->source . '/.source-plugin', 0777, true);
        file_put_contents($this->source . '/.source-plugin/sync.json', '{"stale":true}');

        $result = $this->mirrorSync();

        self::assertSame(['src/Thing.php'], $result->written);
        self::assertDirectoryDoesNotExist($this->mirror . '/vendor');
        self::assertStringNotContainsString(
            'stale',
            (string) file_get_contents($this->mirror . '/.source-plugin/sync.json'),
        );
    }

    public function testAFreshCloneAdoptsLocalFilesAsTheBaseInsteadOfClobberingThem(): void
    {
        mkdir($this->mirror . '/src', 0777, true);
        file_put_contents($this->mirror . '/src/Thing.php', 'pre-existing local file');
        $this->upstream('src/Thing.php', 'upstream version');

        $result = $this->mirrorSync();

        self::assertTrue($result->unbased);
        self::assertSame('pre-existing local file', $this->mirrorFile('src/Thing.php'));
        self::assertNotEmpty($result->warnings);
    }

    public function testItRecordsTheUpstreamReferenceInTheSnapshot(): void
    {
        $this->upstream('src/Thing.php', 'x');

        $this->mirrorSync('deadbeef');

        $state = json_decode((string) file_get_contents($this->mirror . '/.source-plugin/sync.json'), true);
        self::assertSame('deadbeef', $state['reference']);
        self::assertSame('acme/fluent', $state['package']);
    }

    public function testItRebasesAnExistingMirrorWithoutTreatingTheRewriteAsAConflict(): void
    {
        $this->upstream('src/Thing.php', '<?php namespace Webong\\Cogent; class Thing {}');
        $this->mirrorSync();

        $result = $this->mirrorSync(rebases: [new NamespaceAliasDefinition(
            'Webong\\Cogent\\',
            'Zorvia\\Cogent\\',
            NamespaceAliasDefinition::TYPE_REBASE,
            'webong/cogent',
            destination: NamespaceAliasDefinition::DESTINATION_MIRROR,
        )]);

        self::assertSame(['src/Thing.php'], $result->written);
        self::assertSame([], $result->conflicts);
        self::assertStringContainsString('namespace Zorvia\\Cogent', $this->mirrorFile('src/Thing.php'));

        $second = $this->mirrorSync(rebases: [new NamespaceAliasDefinition(
            'Webong\\Cogent\\', 'Zorvia\\Cogent\\', NamespaceAliasDefinition::TYPE_REBASE, 'webong/cogent', destination: NamespaceAliasDefinition::DESTINATION_MIRROR,
        )]);
        self::assertSame([], $second->written);
        self::assertSame([], $second->conflicts);
    }

    public function testItRebasesLocalEditsWhenMigratingAnExistingMirror(): void
    {
        $this->upstream('src/Thing.php', '<?php namespace Webong\\Cogent; class Thing { const VALUE = "upstream"; }');
        $this->mirrorSync();
        file_put_contents($this->mirror . '/src/Thing.php', '<?php namespace Webong\\Cogent; class Thing { const VALUE = "local"; }');

        $this->mirrorSync(rebases: [new NamespaceAliasDefinition(
            'Webong\\Cogent\\', 'Zorvia\\Cogent\\', NamespaceAliasDefinition::TYPE_REBASE, 'webong/cogent', destination: NamespaceAliasDefinition::DESTINATION_MIRROR,
        )]);

        self::assertStringContainsString('namespace Zorvia\\Cogent', $this->mirrorFile('src/Thing.php'));
        self::assertStringContainsString('"local"', $this->mirrorFile('src/Thing.php'));
    }

    public function testUnbasedLocalContentSurvivesRepeatedSyncsAndUpstreamChanges(): void
    {
        mkdir($this->mirror . '/src', 0777, true);
        file_put_contents($this->mirror . '/src/Thing.php', 'local work');
        $this->upstream('src/Thing.php', 'upstream v1');
        $this->mirrorSync();
        $this->mirrorSync();
        self::assertSame('local work', $this->mirrorFile('src/Thing.php'));

        $this->upstream('src/Thing.php', 'upstream v2');
        $result = $this->mirrorSync();
        self::assertSame('local work', $this->mirrorFile('src/Thing.php'));
        self::assertSame(['src/Thing.php'], $result->conflicts);
    }

    private function upstream(string $relative, string $contents): void
    {
        $file = $this->source . '/' . $relative;
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }

        file_put_contents($file, $contents);
    }

    private function mirrorFile(string $relative): string
    {
        self::assertFileExists($this->mirror . '/' . $relative);

        return (string) file_get_contents($this->mirror . '/' . $relative);
    }

    private function mirrorSync(?string $reference = null, array $rebases = []): \Webong\ComposerSource\MirrorResult
    {
        return (new SourceMirror($this->source, $this->mirror, 'acme/fluent', $rebases))->sync($reference);
    }

    private function remove(string $path): void
    {
        if (! is_dir($path)) {
            @unlink($path);

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
