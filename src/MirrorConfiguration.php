<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

use InvalidArgumentException;

final class MirrorConfiguration
{
    /**
     * @param array<string, mixed> $mirrors
     * @param array<string, mixed> $loaders
     * @return list<MirrorDefinition>
     *
     * @throws InvalidArgumentException
     */
    public static function parse(array $mirrors, array $loaders = []): array
    {
        $definitions = [];

        foreach ($mirrors as $package => $configuration) {
            if (! is_string($package) || $package === '') {
                throw new InvalidArgumentException('Mirror keys must be package names.');
            }

            if (array_key_exists($package, $loaders)) {
                throw new InvalidArgumentException(sprintf(
                    'Package [%s] cannot be both mirrored and loadable: a mirror needs the remote package installed, while a loader removes it from the pool. Configure one or the other.',
                    $package,
                ));
            }

            if (is_string($configuration)) {
                $definitions[] = new MirrorDefinition($package, $configuration);

                continue;
            }

            if (! is_array($configuration)) {
                throw new InvalidArgumentException(sprintf(
                    'Mirror [%s] must map to a path or a configuration array.',
                    $package,
                ));
            }

            $path = $configuration['path'] ?? null;
            if (! is_string($path)) {
                throw new InvalidArgumentException(sprintf(
                    'Mirror [%s] must declare a string path.',
                    $package,
                ));
            }

            $origin = $configuration['origin'] ?? MirrorDefinition::ORIGIN_REMOTE;
            if (! is_string($origin)) {
                throw new InvalidArgumentException(sprintf(
                    'Mirror [%s] has a non-string origin.',
                    $package,
                ));
            }

            $definitions[] = new MirrorDefinition($package, $path, $origin);
        }

        return $definitions;
    }

    /**
     * @param array<string, mixed> $mirrors
     * @param array<string, mixed> $loaders
     * @return array<string, MirrorDefinition>
     *
     * @throws InvalidArgumentException
     */
    public static function indexed(array $mirrors, array $loaders = []): array
    {
        $indexed = [];
        foreach (self::parse($mirrors, $loaders) as $definition) {
            $indexed[$definition->package] = $definition;
        }

        return $indexed;
    }
}
