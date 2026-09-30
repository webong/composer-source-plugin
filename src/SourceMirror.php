<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * File-level three-way merge between an installed upstream package and a local
 * mirror working copy.
 *
 * Granularity is deliberately the file, not the line: a file that both sides
 * changed is reported as a conflict and the local copy is left alone, rather
 * than being auto-merged. Nothing here ever overwrites a local edit without
 * either the local file being byte-identical to the last synced state, or the
 * user explicitly applying the upstream copy from the conflict sidecar.
 */
final class SourceMirror
{
    /**
     * Directories inside an installed package that never belong in the mirror.
     *
     * @var list<string>
     */
    private const EXCLUDED_DIRECTORIES = ['vendor', '.git', '.github', MirrorState::DIRECTORY];

    public function __construct(
        private readonly string $sourceRoot,
        private readonly string $mirrorRoot,
        private readonly string $package,
        /** @var list<NamespaceAliasDefinition> */
        private readonly array $rebases = [],
    ) {
    }

    public function sync(?string $reference = null): MirrorResult
    {
        if (! is_dir($this->sourceRoot)) {
            throw new RuntimeException(sprintf(
                'Mirror source for [%s] is not installed at [%s].',
                $this->package,
                $this->sourceRoot,
            ));
        }

        $this->createMirrorRoot();

        $state = MirrorState::load($this->mirrorRoot);
        $base = $state['files'];

        $upstream = $this->upstreamFiles();
        $written = [];
        $unchanged = [];
        $removed = [];
        $preserved = [];
        $conflicts = [];
        $warnings = [];
        $unbased = [];
        $nextBase = $base;

        foreach (array_keys($upstream + $base) as $path) {
            $upstreamHash = $upstream[$path] ?? null;
            $baseHash = $base[$path] ?? null;
            $localHash = $this->hashLocal($path);

            if ($upstreamHash === null) {
                // Upstream no longer ships this file.
                if ($localHash === null) {
                    unset($nextBase[$path]);

                    continue;
                }

                if ($baseHash !== null && $localHash === $baseHash) {
                    $this->deleteLocal($path);
                    unset($nextBase[$path]);
                    $removed[] = $path;

                    continue;
                }

                if ($baseHash === null) {
                    // Never tracked: a local-only file, leave it alone.
                    continue;
                }

                $warnings[] = sprintf(
                    '%s was removed upstream but is locally modified; kept.',
                    $path,
                );

                continue;
            }

            $desiredHash = $upstreamHash === null ? null : $this->desiredHash($path);

            if ($localHash === $desiredHash) {
                $unchanged[] = $path;
                $nextBase[$path] = $desiredHash;

                continue;
            }

            if ($baseHash === null) {
                if ($localHash === null) {
                    $this->writeLocal($path, $this->sourceRoot . '/' . $path);
                    $written[] = $path;
                    $nextBase[$path] = $desiredHash;

                    continue;
                }

                // Record upstream, never the divergent local content. Otherwise
                // the next sync mistakes local work for an untouched upstream
                // file and overwrites it, even when upstream has not changed.
                $preserved[] = $path;
                $nextBase[$path] = $desiredHash;
                $unbased[] = $path;

                continue;
            }

            // A mirror can be switched from upstream namespaces to rebased
            // namespaces without mistaking that mechanical rewrite for an
            // upstream change. Subsequent syncs use the rebased hash as base.
            if ($upstreamHash === $baseHash && $desiredHash !== $upstreamHash) {
                if ($localHash === $baseHash) {
                    $this->writeLocal($path, $this->sourceRoot . '/' . $path);
                    $written[] = $path;
                } else {
                    // Keep developer changes, but apply the same mechanical
                    // namespace transformation to them so a mirror-rebase
                    // migration cannot leave mixed namespaces behind.
                    $this->rebaseLocal($path);
                    $written[] = $path;
                    $preserved[] = $path;
                }
                $nextBase[$path] = $desiredHash;
                continue;
            }

            if ($desiredHash === $baseHash) {
                // Upstream has not touched this file since the last sync, so
                // the difference is ours. Keep it.
                $preserved[] = $path;
                $nextBase[$path] = $desiredHash;

                continue;
            }

            if ($localHash === $baseHash) {
                $this->writeLocal($path, $this->sourceRoot . '/' . $path);
                $written[] = $path;
                $nextBase[$path] = $desiredHash;

                continue;
            }

            // Both sides moved. Never clobber: surface it and park upstream.
            $this->writeSidecar($path, $this->sourceRoot . '/' . $path);
            $conflicts[] = $path;
            // The base deliberately stays at the previous value so the conflict
            // survives until the local file actually matches upstream.
        }

        if ($unbased !== []) {
            $warnings[] = sprintf(
                'No previous sync snapshot for %d file(s); local content preserved and current upstream recorded as the baseline.',
                count($unbased),
            );
        }

        MirrorState::save($this->mirrorRoot, $this->package, $reference ?? $state['reference'], $nextBase);

        return new MirrorResult(
            package: $this->package,
            written: $written,
            unchanged: $unchanged,
            removed: $removed,
            preserved: $preserved,
            conflicts: $conflicts,
            warnings: $warnings,
            unbased: $unbased !== [],
        );
    }

    /**
     * @return array<string, string> relative path => content hash
     */
    private function upstreamFiles(): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->sourceRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relative = $this->relativePath($file->getPathname());
            if ($relative === null) {
                continue;
            }

            $files[$relative] = (string) hash_file('sha256', $file->getPathname());
        }

        ksort($files);

        return $files;
    }

    private function relativePath(string $pathname): ?string
    {
        $relative = ltrim(str_replace($this->sourceRoot, '', $pathname), '/\\');

        if ($relative === '') {
            return null;
        }

        $segments = preg_split('#[/\\\\]#', $relative) ?: [];
        foreach (self::EXCLUDED_DIRECTORIES as $excluded) {
            if (in_array($excluded, $segments, true)) {
                return null;
            }
        }

        return implode('/', $segments);
    }

    private function hashLocal(string $relative): ?string
    {
        $file = $this->mirrorRoot . '/' . $relative;

        if (! is_file($file)) {
            return null;
        }

        $hash = @hash_file('sha256', $file);

        return $hash === false ? null : $hash;
    }

    private function writeLocal(string $relative, string $from): void
    {
        $target = $this->mirrorRoot . '/' . $relative;
        $directory = dirname($target);

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create mirror directory: ' . $directory);
        }

        $contents = file_get_contents($from);
        if ($contents === false) {
            throw new RuntimeException('Unable to read mirrored source file: ' . $from);
        }
        if (pathinfo($from, PATHINFO_EXTENSION) === 'php') {
            foreach ($this->rebases as $rebase) {
                $contents = (new NamespaceRebaser)->rebase($contents, $rebase);
            }
        }
        if (file_put_contents($target, $contents) === false) {
            throw new RuntimeException('Unable to write mirrored file: ' . $target);
        }
    }

    private function desiredHash(string $relative): string
    {
        $contents = file_get_contents($this->sourceRoot . '/' . $relative);
        if ($contents === false) {
            throw new RuntimeException('Unable to read mirrored source file: ' . $relative);
        }
        if (pathinfo($relative, PATHINFO_EXTENSION) === 'php') {
            foreach ($this->rebases as $rebase) {
                $contents = (new NamespaceRebaser)->rebase($contents, $rebase);
            }
        }
        return hash('sha256', $contents);
    }

    private function rebaseLocal(string $relative): void
    {
        $target = $this->mirrorRoot . '/' . $relative;
        if (pathinfo($target, PATHINFO_EXTENSION) !== 'php') {
            return;
        }
        $contents = file_get_contents($target);
        if ($contents === false) {
            throw new RuntimeException('Unable to read local mirror file: ' . $target);
        }
        foreach ($this->rebases as $rebase) {
            $contents = (new NamespaceRebaser)->rebase($contents, $rebase);
        }
        if (file_put_contents($target, $contents) === false) {
            throw new RuntimeException('Unable to rebase local mirror file: ' . $target);
        }
    }

    private function writeSidecar(string $relative, string $from): void
    {
        $this->writeLocal($relative . '.upstream', $from);
    }

    private function deleteLocal(string $relative): void
    {
        $target = $this->mirrorRoot . '/' . $relative;

        if (is_file($target) && ! unlink($target)) {
            throw new RuntimeException('Unable to remove mirrored file: ' . $target);
        }
    }

    private function createMirrorRoot(): void
    {
        if (is_dir($this->mirrorRoot)) {
            return;
        }

        if (! mkdir($this->mirrorRoot, 0777, true) && ! is_dir($this->mirrorRoot)) {
            throw new RuntimeException('Unable to create mirror directory: ' . $this->mirrorRoot);
        }
    }
}
