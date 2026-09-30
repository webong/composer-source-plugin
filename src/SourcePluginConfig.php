<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

use Composer\Composer;
use InvalidArgumentException;

/**
 * Single reader for the plugin's root "extra" section, so the key and its
 * validation live in one place instead of being repeated per feature.
 */
final class SourcePluginConfig
{
    public const EXTRA_KEY = 'source-plugin';

    /** @return array<string, mixed> */
    public static function section(Composer $composer): array
    {
        $extra = $composer->getPackage()->getExtra();

        if (! is_array($extra)) {
            return [];
        }

        $section = $extra[self::EXTRA_KEY] ?? [];

        return is_array($section) ? $section : [];
    }

    /** @return array<string, mixed> */
    public static function mirrors(Composer $composer): array
    {
        $mirrors = self::section($composer)['mirrors'] ?? [];

        return is_array($mirrors) ? $mirrors : [];
    }

    /** @return array<string, mixed> */
    public static function loaders(Composer $composer): array
    {
        $loaders = self::section($composer)['loaders'] ?? [];

        return is_array($loaders) ? $loaders : [];
    }

    /** @return array<string, mixed> */
    public static function aliases(Composer $composer): array
    {
        $aliases = self::section($composer)['aliases'] ?? [];

        return is_array($aliases) ? $aliases : [];
    }

    /**
     * Validate at plugin activation.
     *
     * A package that is both mirrored and loadable is contradictory: a mirror
     * needs the remote package to stay installed so it can be synced and
     * updated, while a loader removes it from the pool. Detecting this during
     * dependency resolution is too late to give a useful message, because the
     * loader has already deselected the package and Composer reports a
     * confusing conflict instead.
     *
     * @throws InvalidArgumentException
     */
    public static function assertValid(Composer $composer): void
    {
        if (! in_array(self::section($composer)['mirror-state'] ?? 'local', ['local', 'lock'], true)) {
            throw new InvalidArgumentException('source-plugin.mirror-state must be local or lock.');
        }
        MirrorConfiguration::parse(self::mirrors($composer), self::loaders($composer));
    }
}
