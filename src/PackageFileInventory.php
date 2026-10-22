<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

use InvalidArgumentException;

/**
 * Enumerates package files without ever following a symlink out of the
 * package root. Symlinked directories are treated as leaf entries; they are
 * never traversed, so projection can unlink or retain the link itself but can
 * never reach through it.
 */
final class PackageFileInventory
{
    /** @return list<string> sorted package-relative file paths */
    public static function files(string $root): array
    {
        $resolved = self::root($root);
        $files = [];
        self::walk($resolved, '', $resolved, $files);
        sort($files);

        return array_values(array_unique($files));
    }

    public static function root(string $root): string
    {
        if (is_link($root)) {
            throw new InvalidArgumentException(sprintf(
                'Cannot project [%s]: the installed package path is a symlink. Use a copied installation rather than a symlinked path repository.',
                $root,
            ));
        }

        $resolved = realpath($root);
        if ($resolved === false || ! is_dir($resolved)) {
            throw new InvalidArgumentException(sprintf(
                'Cannot project [%s]: the installed package directory does not exist.',
                $root,
            ));
        }

        return rtrim(str_replace('\\', '/', $resolved), '/');
    }

    /**
     * @param list<string> $files
     */
    private static function walk(string $root, string $relative, string $directory, array &$files): void
    {
        $entries = scandir($directory);
        if ($entries === false) {
            throw new InvalidArgumentException('Unable to enumerate package directory: ' . $directory);
        }
        sort($entries);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;
            $pathRelative = $relative === '' ? $entry : $relative . '/' . $entry;

            if ($relative === '' && ($entry === '.git' || $entry === ProjectionMarker::DIRECTORY)) {
                // Composer VCS metadata and the plugin's own marker are never
                // part of an author-declared surface.
                continue;
            }

            if (is_link($path)) {
                $target = realpath($path);
                if ($target === false || ! self::within($target, $root)) {
                    throw new InvalidArgumentException(sprintf(
                        'Symlink [%s] points outside the package root and cannot be projected safely.',
                        $pathRelative,
                    ));
                }

                $files[] = $pathRelative;

                continue;
            }

            if (is_dir($path)) {
                self::walk($root, $pathRelative, $path, $files);

                continue;
            }

            if (is_file($path)) {
                $files[] = $pathRelative;
                continue;
            }

            throw new InvalidArgumentException(sprintf(
                'Unsupported filesystem entry [%s] in package root.',
                $pathRelative,
            ));
        }
    }

    private static function within(string $path, string $root): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');

        return $path === $root || str_starts_with($path, $root . '/');
    }
}
