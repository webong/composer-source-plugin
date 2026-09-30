<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

/**
 * Records what the mirror looked like when it was last synced from upstream, so
 * the next sync can tell "upstream changed this" apart from "we changed this".
 *
 * The snapshot lives inside the mirror working copy on purpose: it is committed
 * alongside the working copy so the merge base is reproducible across machines
 * and survives a cleared Composer cache.
 */
final class MirrorState
{
    public const DIRECTORY = '.source-plugin';

    public const FILE = 'sync.json';

    public const VERSION = 1;

    /**
     * @return array{reference: string|null, files: array<string, string>}
     */
    public static function load(string $mirrorRoot): array
    {
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
            return ['reference' => null, 'files' => []];
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
    public static function save(string $mirrorRoot, string $package, ?string $reference, array $files): void
    {
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
}
