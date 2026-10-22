<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

use RuntimeException;

/**
 * Projection entries inside composer-source.lock.
 *
 * The entry is deliberately machine-independent: no absolute install path,
 * only the package name, installed reference/version, selected surfaces,
 * normalized patterns and their digests.
 */
final class ProjectionState
{
    public const FORMAT = 1;

    /** @return array<string, mixed>|null */
    public static function entry(string $lockFile, string $package): ?array
    {
        $lock = SourceLock::read($lockFile);
        $entry = $lock['projections'][$package] ?? null;
        if ($entry === null) {
            return null;
        }

        return self::validate($entry, $package, $lockFile);
    }

    /**
     * @param array<string, mixed> $entry
     */
    public static function save(string $lockFile, string $package, array $entry): void
    {
        $entry = self::validate($entry, $package, $lockFile);
        $lock = SourceLock::read($lockFile);
        $lock['projections'][$package] = $entry;
        SourceLock::write($lockFile, $lock);
    }

    /**
     * @param mixed $entry
     * @return array<string, mixed>
     */
    public static function validate(mixed $entry, string $package, string $lockFile): array
    {
        if (! is_array($entry)
            || ($entry['format'] ?? null) !== self::FORMAT
            || ($entry['reference'] ?? null) !== null && ! is_string($entry['reference'] ?? null)
            || ($entry['version'] ?? null) !== null && ! is_string($entry['version'] ?? null)
            || ! is_array($entry['surfaces'] ?? null)
            || ! array_is_list($entry['surfaces'])
            || $entry['surfaces'] === []
            || ! is_array($entry['patterns'] ?? null)
            || ! array_is_list($entry['patterns'])
            || ! is_string($entry['patterns_digest'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $entry['patterns_digest']) !== 1
            || ! is_string($entry['definition_digest'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $entry['definition_digest']) !== 1) {
            throw new RuntimeException(sprintf(
                'Invalid projection state for [%s] in %s.',
                $package,
                $lockFile,
            ));
        }

        foreach ($entry['surfaces'] as $surface) {
            if (! is_string($surface) || $surface === '') {
                throw new RuntimeException(sprintf(
                    'Invalid projection state for [%s] in %s.',
                    $package,
                    $lockFile,
                ));
            }
        }

        foreach ($entry['patterns'] as $pattern) {
            if (! SourceLock::isSafeRelativePath($pattern)) {
                throw new RuntimeException(sprintf(
                    'Invalid projection state for [%s] in %s.',
                    $package,
                    $lockFile,
                ));
            }
        }

        return $entry;
    }
}
