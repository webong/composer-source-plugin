<?php

declare(strict_types=1);

namespace Webong\ComposerSource\Tests;

use PHPUnit\Framework\TestCase;
use Webong\ComposerSource\PublicationException;
use Webong\ComposerSource\PublicationGit;
use Webong\ComposerSource\PublicationHistory;

final class PublicationHistoryTest extends TestCase
{
    private string $root;
    private PublicationGit $git;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/publication-history-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        $this->git = new PublicationGit($this->root, ['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_SYSTEM' => '/dev/null', 'GIT_AUTHOR_NAME' => 'Fixture', 'GIT_COMMITTER_NAME' => 'Fixture', 'GIT_AUTHOR_EMAIL' => 'fixture@example.test', 'GIT_COMMITTER_EMAIL' => 'fixture@example.test']);
        $this->git->out(['init', '--bare', '-q']);
    }

    protected function tearDown(): void
    {
        $remove = function (string $path) use (&$remove): void {
            if (is_dir($path) && ! is_link($path)) {
                foreach (scandir($path) ?: [] as $name) {
                    if ($name !== '.' && $name !== '..') {
                        $remove($path.'/'.$name);
                    }
                }
                rmdir($path);
            } else {
                unlink($path);
            }
        };
        $remove($this->root);
    }

    public function testItPreservesExactHistoricalRecordsAndConventionalAlias(): void
    {
        $old = $this->record();
        $old['require-dev'] = ['legacy/testing' => '^1'];
        $this->index(['packages.json' => ['notify-batch' => 'https://example.com/notify', 'packages' => ['acme/core' => ['0.2.0' => $old]]]]);
        $history = new PublicationHistory($this->git, 'dist/composer');
        $candidate = $this->record();
        $candidate['source']['url'] = 'https://new-host.example.com/source';
        $merged = $history->merge(['packages' => ['acme/core' => ['0.2.0' => $candidate]]]);
        self::assertSame($old, $merged['packages']['acme/core']['0.2.0']);
        self::assertSame('https://example.com/notify', $merged['notify-batch']);
        self::assertSame(['composer-packages.json', 'packages.json'], $history->filenames);
    }

    public function testRetiredPackagesRemainAvailableWithoutOldDevelopmentRecords(): void
    {
        $old = $this->record();
        $this->index(['packages.json' => ['packages' => ['acme/core' => ['0.2.0' => $old, 'dev-main' => ['name' => 'acme/core', 'version' => 'dev-main']]]]]);
        $merged = (new PublicationHistory($this->git, 'dist/composer'))->merge(['packages' => []]);
        self::assertSame(['0.2.0' => $old], $merged['packages']['acme/core']);
    }

    public function testAChangedReferenceCannotReplaceAnExistingVersion(): void
    {
        $this->index(['packages.json' => ['packages' => ['acme/core' => ['0.2.0' => $this->record()]]]]);
        $changed = $this->record();
        $changed['source']['reference'] = str_repeat('b', 40);
        $this->expectException(PublicationException::class);
        $this->expectExceptionMessage('different source reference');
        (new PublicationHistory($this->git, 'dist/composer'))->merge(['packages' => ['acme/core' => ['0.2.0' => $changed]]]);
    }

    public function testDisagreeingAliasesAreRejected(): void
    {
        $this->index(['packages.json' => ['packages' => ['acme/core' => ['0.2.0' => $this->record()]]], 'composer-packages.json' => ['packages' => []]]);
        $this->expectException(PublicationException::class);
        $this->expectExceptionMessage('aliases disagree');
        new PublicationHistory($this->git, 'dist/composer');
    }

    public function testUnrelatedBranchFilesAreNotOverwritten(): void
    {
        $this->index(['packages.json' => ['packages' => []], 'unrelated.json' => ['important' => true]]);
        $this->expectException(PublicationException::class);
        $this->expectExceptionMessage('unrelated');
        new PublicationHistory($this->git, 'dist/composer');
    }

    public function testAliasTraversalIsRejected(): void
    {
        $this->expectException(PublicationException::class);
        new PublicationHistory($this->git, 'dist/composer', '../outside.json');
    }

    public function testAmbiguousNormalizedVersionsAreRejected(): void
    {
        $record = $this->record();
        $other = $record;
        $other['version'] = 'v0.2.0';
        $this->index(['packages.json' => ['packages' => ['acme/core' => ['0.2.0' => $record, 'v0.2.0' => $other]]]]);
        $this->expectException(PublicationException::class);
        $this->expectExceptionMessage('ambiguous');
        (new PublicationHistory($this->git, 'dist/composer'))->merge(['packages' => []]);
    }

    private function record(): array
    {
        return ['name' => 'acme/core', 'version' => '0.2.0', 'source' => ['type' => 'git', 'url' => 'https://old-host.example.com/source', 'reference' => str_repeat('a', 40)], 'dist' => ['type' => 'zip', 'url' => 'https://old-host.example.com/archive.zip', 'reference' => str_repeat('a', 40), 'shasum' => 'original-shasum']];
    }

    private function index(array $files): void
    {
        $entries = [];
        foreach ($files as $name => $data) {
            $blob = $this->git->out(['hash-object', '-w', '--stdin'], json_encode($data, JSON_THROW_ON_ERROR));
            $entries[] = "100644 blob $blob\t$name\n";
        }
        $tree = $this->git->out(['mktree'], implode('', $entries));
        $commit = $this->git->out(['commit-tree', $tree], "fixture\n");
        $this->git->out(['update-ref', 'refs/heads/dist/composer', $commit]);
    }
}
