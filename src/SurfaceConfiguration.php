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
        if ($surfaces !== [] && array_is_list($surfaces)) {
            throw new InvalidArgumentException('Manifest patterns require an installed package root; use fromPackageRoot().');
        }
        $raw = [];
        foreach ($surfaces as $name => $configuration) {
            if (! is_string($name) || trim($name) === '') {
                throw new InvalidArgumentException('Surface names must be non-empty strings.');
            }
            $name = trim($name);
            if (isset($raw[$name])) {
                throw new InvalidArgumentException(sprintf('Surface [%s] is declared more than once.', $name));
            }
            $raw[$name] = $configuration;
        }
        return self::resolve($raw);
    }

    /** @param array<string, mixed> $raw @param list<string> $manifests @return array<string, SurfaceDefinition> */
    public static function resolve(array $raw, array $manifests = []): array
    {
        $definitions = [];
        $visiting = [];
        $visit = function (string $name) use (&$visit, &$definitions, &$visiting, $raw, $manifests): SurfaceDefinition {
            if (isset($definitions[$name])) {
                return $definitions[$name];
            }
            if (isset($visiting[$name])) {
                throw new InvalidArgumentException('Surface inheritance cycle at ['.$name.'].');
            }
            if (! array_key_exists($name, $raw)) {
                throw new InvalidArgumentException('Unknown parent surface ['.$name.'].');
            }
            if (count($visiting) >= 128) {
                throw new InvalidArgumentException('Surface inheritance exceeds 128 levels.');
            }
            $visiting[$name] = true;
            $config = $raw[$name];
            $parents = is_array($config) ? ($config['extends'] ?? []) : [];
            if (is_string($parents)) {
                $parents = [$parents];
            }
            if (! is_array($parents) || ! array_is_list($parents)) {
                throw new InvalidArgumentException('Surface extends must be a name or list of names.');
            }
            if (is_array($config) && ! array_is_list($config)) {
                unset($config['extends']);
            }
            $include = [];
            $exclude = [];
            foreach ($parents as $parent) {
                if (! is_string($parent) || trim($parent) === '') {
                    throw new InvalidArgumentException('Surface parents must be non-empty names.');
                }
                $base = $visit(trim($parent));
                $include = array_merge($include, $base->include);
                $exclude = array_merge($exclude, $base->exclude);
            }
            if ($parents !== [] && is_array($config)) {
                foreach (['include', 'exclude'] as $key) {
                    if (isset($config[$key]) && (! is_array($config[$key]) || ! array_is_list($config[$key]))) {
                        throw new InvalidArgumentException('Surface '.$key.' must be a list of paths.');
                    }
                }
                $config['include'] = array_merge($include, $config['include'] ?? []);
                $config['exclude'] = array_merge($exclude, $config['exclude'] ?? []);
            }
            $definition = SurfaceDefinition::parse($name, $config);
            unset($visiting[$name]);
            return $definitions[$name] = new SurfaceDefinition($name, $definition->include, $definition->exclude, $manifests);
        };
        foreach (array_keys($raw) as $name) {
            $visit((string) $name);
        }
        ksort($definitions);
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
            throw new InvalidArgumentException('Installed package surfaces must be a map of definitions or a list of manifest patterns.');
        }

        return $surfaces !== [] && array_is_list($surfaces)
            ? SurfaceDiscovery::discover($root, $surfaces)
            : self::parse($surfaces);
    }

    /**
     * @param array<string, SurfaceDefinition> $surfaces
     */
    public static function digest(array $surfaces): string
    {
        $canonical = [];
        ksort($surfaces);
        foreach ($surfaces as $name => $surface) {
            $canonical[$name] = $surface->exclude === [] && $surface->manifests === []
                ? $surface->include
                : $surface->metadata();
        }

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
