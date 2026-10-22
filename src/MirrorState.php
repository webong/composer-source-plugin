<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

use RuntimeException;

/**
 * Records what the mirror looked like when it was last synced from upstream, so
 * the next sync can tell "upstream changed this" apart from "we changed this".
 *
 * Existing consumers keep their per-mirror snapshot. Project-lock consumers
 * share a committed baseline beside the Composer manifest, independent of
 * Composer's cache and its dependency-lock rewrites.
 */
final class MirrorState
{
    public const DIRECTORY = '.source-plugin';

    public const FILE = 'sync.json';

    public const VERSION = SourceLock::VERSION;

    /**
     * @return array{reference: string|null, files: array<string, string>}
     */
    public static function load(string $mirrorRoot, ?string $lockFile = null, ?string $package = null): array
    {
        if ($lockFile !== null) {
            $lock = self::readLock($lockFile);
            if (isset($lock['mirrors'][$package])) {
                return $lock['mirrors'][$package];
            }
        }
        $file = self::pathFor($mirrorRoot);
        if (! is_file($file)) {
            return ['reference' => null, 'files' => []];
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            return ['reference' => null, 'files' => []];
        }

        $decoded = json_decode($contents, true);
        if (! is_array($decoded) || ($decoded['version'] ?? null) !== self::VERSION) {
            if ($lockFile !== null) {
                throw new RuntimeException('Cannot migrate invalid legacy mirror state: ' . $file);
            }
            return ['reference' => null, 'files' => []];
        }
        if ($lockFile !== null && ($decoded['package'] ?? null) !== $package) {
            throw new RuntimeException('Legacy mirror state belongs to a different package: ' . $file);
        }

        $files = [];
        foreach (is_array($decoded['files'] ?? null) ? $decoded['files'] : [] as $path => $hash) {
            if (is_string($path) && $path !== '' && is_string($hash) && $hash !== '') {
                $files[$path] = $hash;
            }
        }

        $reference = $decoded['reference'] ?? null;

        return [
            'reference' => is_string($reference) ? $reference : null,
            'files' => $files,
        ];
    }

    /**
     * @param array<string, string> $files
     */
    public static function save(string $mirrorRoot, string $package, ?string $reference, array $files, ?string $lockFile = null, array $namespaces = []): void
    {
        if ($lockFile !== null) {
            $lock = SourceLock::read($lockFile);
            ksort($files);
            ksort($namespaces);
            $lock['mirrors'][$package] = ['reference' => $reference, 'files' => $files, 'namespaces' => $namespaces];
            SourceLock::write($lockFile, $lock);
            // Delete only a recognized legacy baseline, after the new state is durable.
            $legacy = self::pathFor($mirrorRoot);
            if (is_file($legacy)) {
                $old = json_decode((string) file_get_contents($legacy), true);
                if (is_array($old) && ($old['version'] ?? null) === self::VERSION && ($old['package'] ?? null) === $package) {
                    if (! unlink($legacy)) {
                        throw new RuntimeException('Saved mirror lock but could not remove legacy state: ' . $legacy);
                    }
                    if (scandir(dirname($legacy)) === ['.', '..']) {
                        rmdir(dirname($legacy));
                    }
                }
            }
            return;
        }
        $file = self::pathFor($mirrorRoot);
        $directory = dirname($file);

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            return;
        }

        ksort($files);

        file_put_contents($file, json_encode([
            'version' => self::VERSION,
            'package' => $package,
            'reference' => $reference,
            'files' => $files,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    public static function pathFor(string $mirrorRoot): string
    {
        return rtrim($mirrorRoot, '/\\') . '/' . self::DIRECTORY . '/' . self::FILE;
    }

    /** @return array<string, mixed> */
    public static function readLock(string $file): array
    {
        return SourceLock::read($file);
    }
}
