<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

use Composer\Factory;
use RuntimeException;

/**
 * Shared, atomic reader/writer for composer-source.lock.
 *
 * Mirrors and package projections both live here so one feature can never
 * erase the other's state when Composer rewrites its own lock file.
 */
final class SourceLock
{
    public const VERSION = 1;

    private const FILE_SUFFIX = '-source.lock';

    public static function path(): string
    {
        $manifest = Factory::getComposerFile();
        $directory = dirname($manifest);

        return ($directory === '.' ? '' : $directory . '/')
            . pathinfo($manifest, PATHINFO_FILENAME)
            . self::FILE_SUFFIX;
    }

    /** @return array<string, mixed> */
    public static function read(string $file): array
    {
        if (! file_exists($file)) {
            return ['version' => self::VERSION, 'mirrors' => [], 'projections' => []];
        }

        $contents = file_get_contents($file);
        $lock = $contents === false ? null : json_decode($contents, true);
        if (! is_array($lock) || ($lock['version'] ?? null) !== self::VERSION) {
            throw new RuntimeException('Invalid or unsupported source plugin lock: ' . $file);
        }

        $mirrors = $lock['mirrors'] ?? [];
        if (! is_array($mirrors)) {
            throw new RuntimeException('Invalid or unsupported source plugin lock: ' . $file);
        }

        foreach ($mirrors as $package => $state) {
            if (! is_string($package) || ! is_array($state) || ! array_key_exists('reference', $state)
                || ($state['reference'] !== null && ! is_string($state['reference']))
                || ! is_array($state['files'] ?? null) || ! is_array($state['namespaces'] ?? null)) {
                throw new RuntimeException('Invalid mirror state in: ' . $file);
            }
            foreach ($state['files'] as $path => $hash) {
                if (! self::isSafeRelativePath($path)
                    || ! is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                    throw new RuntimeException('Invalid mirror file entry in: ' . $file);
                }
            }
        }

        $projections = $lock['projections'] ?? [];
        if (! is_array($projections)) {
            throw new RuntimeException('Invalid projection state in: ' . $file);
        }

        $lock['version'] = self::VERSION;
        $lock['mirrors'] = $mirrors;
        $lock['projections'] = $projections;

        return $lock;
    }

    /** @param array<string, mixed> $lock */
    public static function write(string $file, array $lock): void
    {
        $lock['version'] = self::VERSION;
        if (! is_array($lock['mirrors'] ?? null)) {
            $lock['mirrors'] = [];
        }
        if (! is_array($lock['projections'] ?? null)) {
            $lock['projections'] = [];
        }
        ksort($lock['mirrors']);
        ksort($lock['projections']);

        $contents = json_encode(
            $lock,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";

        if (is_file($file) && file_get_contents($file) === $contents) {
            return;
        }

        $temporary = tempnam(dirname($file), '.source-lock-');
        if ($temporary === false) {
            throw new RuntimeException('Unable to prepare source plugin lock: ' . $file);
        }

        try {
            if (file_put_contents($temporary, $contents) !== strlen($contents)
                || ! rename($temporary, $file)) {
                throw new RuntimeException('Unable to save source plugin lock: ' . $file);
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public static function isSafeRelativePath(mixed $path): bool
    {
        return is_string($path)
            && $path !== ''
            && ! str_starts_with($path, '/')
            && ! str_contains($path, '\\')
            && ! str_contains($path, ':')
            && ! in_array('..', explode('/', $path), true);
    }
}
