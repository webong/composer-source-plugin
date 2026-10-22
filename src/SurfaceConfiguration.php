<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

use InvalidArgumentException;
use RuntimeException;

final class SurfaceConfiguration
{
    /**
     * @param array<string, mixed> $surfaces
     * @return array<string, SurfaceDefinition> keyed by surface name, sorted
     */
    public static function parse(array $surfaces): array
    {
        $definitions = [];
        ksort($surfaces);

        foreach ($surfaces as $name => $configuration) {
            if (! is_string($name) || trim($name) === '') {
                throw new InvalidArgumentException('Surface names must be non-empty strings.');
            }

            $name = trim($name);
            if (isset($definitions[$name])) {
                throw new InvalidArgumentException(sprintf('Surface [%s] is declared more than once.', $name));
            }

            $definitions[$name] = SurfaceDefinition::parse($name, $configuration);
        }

        return $definitions;
    }

    /**
     * Read author-declared surfaces from an installed package's root metadata.
     *
     * @return array<string, SurfaceDefinition>
     */
    public static function fromPackageRoot(string $root): array
    {
        $manifest = rtrim($root, '/\\') . '/composer.json';
        if (! is_file($manifest)) {
            return [];
        }

        $contents = file_get_contents($manifest);
        $decoded = $contents === false ? null : json_decode($contents, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Unable to parse the installed package metadata at ' . $manifest . '.');
        }

        $extra = $decoded['extra'] ?? [];
        if (! is_array($extra)) {
            return [];
        }

        $section = $extra[SourcePluginConfig::EXTRA_KEY] ?? [];
        if (! is_array($section)) {
            return [];
        }

        $surfaces = $section['surfaces'] ?? [];
        if (! is_array($surfaces)) {
            throw new InvalidArgumentException('Installed package surfaces must be a map of surface names.');
        }

        return self::parse($surfaces);
    }

    /**
     * @param array<string, SurfaceDefinition> $surfaces
     */
    public static function digest(array $surfaces): string
    {
        $canonical = [];
        ksort($surfaces);
        foreach ($surfaces as $name => $surface) {
            $canonical[$name] = $surface->include;
        }

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
