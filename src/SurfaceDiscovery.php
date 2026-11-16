<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

use InvalidArgumentException;
use RuntimeException;

/** Resolve manifest globs without following filesystem links or executing manifests. */
final class SurfaceDiscovery
{
    /** @param list<string> $patterns @return array<string, SurfaceDefinition> */
    public static function discover(string $root, array $patterns): array
    {
        foreach ($patterns as &$pattern) {
            if (! is_string($pattern)) {
                throw new InvalidArgumentException('Surface manifest patterns must be strings.');
            }
            $pattern = IncludePattern::normalize($pattern);
        }
        unset($pattern);
        $files = PackageFileInventory::files($root);
        foreach ($patterns as $pattern) {
            if (strpbrk($pattern, '*?[') === false && ! in_array($pattern, $files, true)) {
                throw new InvalidArgumentException('Surface manifest does not exist: '.$pattern);
            }
        }
        $raw = [];
        $manifests = [];
        foreach ($files as $path) {
            if (! IncludePattern::matchesAny($patterns, $path)) {
                continue;
            }
            if ($path === 'composer.json') {
                throw new InvalidArgumentException('The package root composer.json cannot own a discovered surface.');
            }
            if (is_link($root.'/'.$path)) {
                throw new InvalidArgumentException('Surface manifests must be regular files, not symlinks: '.$path);
            }
            $decoded = json_decode((string) file_get_contents($root.'/'.$path), true);
            if (! is_array($decoded)) {
                throw new RuntimeException('Unable to parse surface manifest: '.$path);
            }
            $marker = $decoded['extra']['source-plugin']['surface'] ?? null;
            if ($marker === null) {
                continue; // An ordinary nested package is not implicitly a surface.
            }
            if ($marker === true) {
                $marker = [];
            }
            if (! is_array($marker) || ($marker !== [] && array_is_list($marker))) {
                throw new InvalidArgumentException('Surface marker must be true or an object: '.$path);
            }
            foreach (array_keys($marker) as $key) {
                if (! in_array($key, ['name', 'extends', 'include', 'exclude'], true)) {
                    throw new InvalidArgumentException('Unsupported surface marker key ['.$key.'] in '.$path);
                }
            }
            $directory = dirname($path);
            $defaultName = strtolower((string) preg_replace('/(?<=[a-z0-9])([A-Z])/', '-$1', basename($directory)));
            $name = $marker['name'] ?? $defaultName;
            if (! is_string($name) || $name === '' || isset($raw[$name])) {
                throw new InvalidArgumentException('Surface names must be unique, non-empty strings: '.$path);
            }
            $include = array_key_exists('include', $marker) ? [] : [$directory];
            $exclude = [];
            foreach (['include', 'exclude'] as $key) {
                $values = $marker[$key] ?? [];
                if (! is_array($values) || ! array_is_list($values)) {
                    throw new InvalidArgumentException('Surface '.$key.' must be a list: '.$path);
                }
                foreach ($values as $value) {
                    if (! is_string($value)) {
                        throw new InvalidArgumentException('Surface patterns must be strings: '.$path);
                    }
                    // Normalize before prefixing so ../ cannot escape the owning group.
                    $normalized = IncludePattern::normalize($value);
                    if ($key === 'include') {
                        $include[] = $directory.'/'.$normalized;
                    } else {
                        $exclude[] = $directory.'/'.$normalized;
                    }
                }
            }
            $raw[$name] = ['include' => $include, 'exclude' => $exclude, 'extends' => $marker['extends'] ?? []];
            $manifests[] = $path;
        }
        if ($raw === []) {
            throw new InvalidArgumentException('No marked surface manifests matched the configured patterns.');
        }
        sort($manifests);
        return SurfaceConfiguration::resolve($raw, $manifests);
    }
}
