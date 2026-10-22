<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

use RuntimeException;

/**
 * Applies an approved projection plan with a staged, atomic directory swap.
 *
 * Nothing outside the package root is ever deleted or modified. Symlinks are
 * recreated as links, never followed for deletion.
 */
final class PackageProjector
{
    public function __construct(private readonly string $packageRoot)
    {
    }

    public function project(ProjectionPlan $plan): ProjectionResult
    {
        $root = PackageFileInventory::root($this->packageRoot);
        if (rtrim(str_replace('\\', '/', $plan->packageRoot), '/') !== $root) {
            throw new RuntimeException('Projection plan root changed before it could be applied.');
        }

        $current = PackageFileInventory::files($root);
        $existingMarker = ProjectionMarker::read($root);
        $desiredMarker = ProjectionMarker::encode($this->canonicalMarker($plan->marker));
        $existingMarkerContents = $existingMarker === null
            ? null
            : ProjectionMarker::encode($this->canonicalMarker($existingMarker));

        $sourceWarning = is_dir($root . '/.git')
            ? ['Projected a VCS/source installation. Composer may refuse later source updates because the checkout now contains deleted files; prefer dist installations or run composer reinstall.']
            : [];

        if ($existingMarkerContents === $desiredMarker && $current === $plan->keep) {
            return new ProjectionResult(
                package: $plan->package,
                changed: false,
                kept: count($plan->keep),
                removed: [],
                warnings: $sourceWarning,
            );
        }

        $parent = dirname($root);
        if (! is_dir($parent) || ! is_writable($parent)) {
            throw new RuntimeException(sprintf(
                'Cannot project [%s]: the package parent directory is not writable.',
                $plan->package,
            ));
        }

        $suffix = bin2hex(random_bytes(6));
        $base = basename($root);
        $staging = $parent . '/.' . $base . '.projection-' . $suffix;
        $backup = $parent . '/.' . $base . '.projection-old-' . $suffix;

        $this->removeTree($staging);
        if (! mkdir($staging, 0777, true) && ! is_dir($staging)) {
            throw new RuntimeException('Unable to create projection staging directory: ' . $staging);
        }

        try {
            foreach ($plan->keep as $relative) {
                $this->copyEntry($root . '/' . $relative, $staging . '/' . $relative, $root);
            }

            if (is_dir($root . '/.git') || is_link($root . '/.git')) {
                $this->copyEntry($root . '/.git', $staging . '/.git', $root);
            }

            $markerFile = ProjectionMarker::path($staging);
            $markerDirectory = dirname($markerFile);
            if (! is_dir($markerDirectory) && ! mkdir($markerDirectory, 0777, true) && ! is_dir($markerDirectory)) {
                throw new RuntimeException('Unable to create projection marker directory: ' . $markerDirectory);
            }
            if (file_put_contents($markerFile, $desiredMarker) === false) {
                throw new RuntimeException('Unable to write projection marker: ' . $markerFile);
            }
        } catch (\Throwable $exception) {
            $this->removeTree($staging);
            throw $exception;
        }

        if (! rename($root, $backup)) {
            $this->removeTree($staging);
            throw new RuntimeException('Unable to move the installed package aside for projection: ' . $root);
        }

        if (! rename($staging, $root)) {
            rename($backup, $root);
            $this->removeTree($staging);
            throw new RuntimeException('Unable to swap the projected package into place: ' . $root);
        }

        $warnings = $sourceWarning;
        try {
            $this->removeTree($backup);
        } catch (\Throwable $exception) {
            $warnings[] = sprintf(
                'Projection succeeded but the old package tree could not be removed: %s',
                $exception->getMessage(),
            );
        }

        return new ProjectionResult(
            package: $plan->package,
            changed: true,
            kept: count($plan->keep),
            removed: $plan->remove,
            warnings: $warnings,
        );
    }

    /**
     * @param array<string, mixed> $marker
     * @return array<string, mixed>
     */
    private function canonicalMarker(array $marker): array
    {
        return ProjectionMarker::create(
            (string) ($marker['package'] ?? ''),
            is_string($marker['reference'] ?? null) ? $marker['reference'] : null,
            is_string($marker['version'] ?? null) ? $marker['version'] : null,
            is_array($marker['surfaces'] ?? null) ? array_values($marker['surfaces']) : [],
            is_array($marker['patterns'] ?? null) ? array_values($marker['patterns']) : [],
            (string) ($marker['patterns_digest'] ?? ''),
            (string) ($marker['definition_digest'] ?? ''),
            is_array($marker['removed'] ?? null) ? array_values($marker['removed']) : [],
        );
    }

    private function copyEntry(string $source, string $target, string $root): void
    {
        if (is_link($source)) {
            $link = readlink($source);
            if ($link === false) {
                throw new RuntimeException('Unable to read package symlink: ' . $source);
            }

            if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0777, true) && ! is_dir(dirname($target))) {
                throw new RuntimeException('Unable to create projection directory: ' . dirname($target));
            }
            if (! symlink($link, $target)) {
                throw new RuntimeException('Unable to recreate package symlink: ' . $target);
            }

            return;
        }

        if (is_dir($source)) {
            $mode = fileperms($source) & 0777;
            if (! is_dir($target) && ! mkdir($target, $mode === 0 ? 0777 : $mode, true) && ! is_dir($target)) {
                throw new RuntimeException('Unable to create projection directory: ' . $target);
            }

            $entries = scandir($source);
            if ($entries === false) {
                throw new RuntimeException('Unable to read package directory: ' . $source);
            }
            sort($entries);
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $this->copyEntry($source . '/' . $entry, $target . '/' . $entry, $root);
            }

            return;
        }

        if (! is_file($source)) {
            throw new RuntimeException('Unsupported package entry: ' . $source);
        }

        if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0777, true) && ! is_dir(dirname($target))) {
            throw new RuntimeException('Unable to create projection directory: ' . dirname($target));
        }
        if (! copy($source, $target)) {
            throw new RuntimeException('Unable to copy retained package file: ' . $source);
        }

        $mode = fileperms($source) & 0777;
        if ($mode !== 0) {
            chmod($target, $mode);
        }
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (! @unlink($path) && (is_link($path) || is_file($path))) {
                throw new RuntimeException('Unable to remove projection temporary file: ' . $path);
            }

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if ($entries === false) {
            throw new RuntimeException('Unable to read projection temporary directory: ' . $path);
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path . '/' . $entry);
        }

        if (! @rmdir($path) && is_dir($path)) {
            throw new RuntimeException('Unable to remove projection temporary directory: ' . $path);
        }
    }
}
