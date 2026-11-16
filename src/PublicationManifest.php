<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

/** Fail before writing publication refs when package-relative autoload/bin paths escape. */
final class PublicationManifest
{
    /** @param array<string, mixed> $manifest */
    public static function validate(array $manifest): void
    {
        $paths = (array) ($manifest['bin'] ?? []);
        foreach (['autoload', 'autoload-dev'] as $section) {
            foreach ($manifest[$section] ?? [] as $type => $entries) {
                if (in_array($type, ['psr-4', 'psr-0'], true)) {
                    foreach ($entries as $directories) {
                        array_push($paths, ...(array) $directories);
                    }
                } elseif (in_array($type, ['files', 'classmap', 'exclude-from-classmap'], true)) {
                    foreach ($entries as $entry) {
                        // Composer permits a leading slash in classmap exclusion patterns.
                        $paths[] = $type === 'exclude-from-classmap' && is_string($entry) ? ltrim($entry, '/') : $entry;
                    }
                }
            }
        }
        foreach ($paths as $path) {
            if ($path === '') {
                continue; // an empty PSR root means this package's root
            }
            if (! SourceLock::isSafeRelativePath($path) || preg_match('/[\x00-\x1f\x7f]/', (string) $path)) {
                throw new PublicationException('Autoload/bin paths must stay within the package: '.($manifest['name'] ?? '?'));
            }
        }
    }
}
