<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

use RuntimeException;

/**
 * The marker left inside a projected package. It is the plugin's only record
 * of what was removed, which is what makes widening detectable without a
 * reinstall.
 */
final class ProjectionMarker
{
    public const DIRECTORY = '.source-plugin';

    public const FILE = 'projection.json';

    public const FORMAT = 1;

    public static function path(string $packageRoot): string
    {
        return rtrim($packageRoot, '/\\') . '/' . self::DIRECTORY . '/' . self::FILE;
    }

    /**
     * @param list<string> $surfaces
     * @param list<string> $patterns
     * @param list<string> $removed
     * @return array<string, mixed>
     */
    public static function create(
        string $package,
        ?string $reference,
        ?string $version,
        array $surfaces,
        array $patterns,
        string $patternsDigest,
        string $definitionDigest,
        array $removed,
    ): array {
        sort($surfaces);
        sort($patterns);
        sort($removed);

        return [
            'format' => self::FORMAT,
            'package' => $package,
            'reference' => $reference,
            'version' => $version,
            'surfaces' => array_values(array_unique($surfaces)),
            'patterns' => array_values(array_unique($patterns)),
            'patterns_digest' => $patternsDigest,
            'definition_digest' => $definitionDigest,
            'removed' => array_values(array_unique($removed)),
        ];
    }

    /** @return array<string, mixed>|null */
    public static function read(string $packageRoot): ?array
    {
        $file = self::path($packageRoot);
        if (! is_file($file)) {
            return null;
        }

        $contents = file_get_contents($file);
        $decoded = $contents === false ? null : json_decode($contents, true);
        if (! is_array($decoded) || ($decoded['format'] ?? null) !== self::FORMAT) {
            throw new RuntimeException('Invalid package projection marker: ' . $file);
        }

        foreach (['package', 'patterns_digest', 'definition_digest'] as $key) {
            if (! is_string($decoded[$key] ?? null) || $decoded[$key] === '') {
                throw new RuntimeException('Invalid package projection marker: ' . $file);
            }
        }

        foreach (['surfaces', 'patterns', 'removed'] as $key) {
            if (! is_array($decoded[$key] ?? null) || ! array_is_list($decoded[$key])) {
                throw new RuntimeException('Invalid package projection marker: ' . $file);
            }
            foreach ($decoded[$key] as $path) {
                if (! is_string($path) || $path === '') {
                    throw new RuntimeException('Invalid package projection marker: ' . $file);
                }
            }
        }

        return $decoded;
    }

    /** @param array<string, mixed> $marker */
    public static function encode(array $marker): string
    {
        return json_encode($marker, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }
}
