<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

use InvalidArgumentException;

final class ProjectionConfiguration
{
    public const PLUGIN_PACKAGE = 'webong/composer-source-plugin';

    /**
     * @param array<string, mixed> $projections
     * @param array<string, mixed> $mirrors
     * @param array<string, mixed> $loaders
     * @return array<string, ProjectionDefinition> keyed by package name
     */
    public static function parse(array $projections, array $mirrors = [], array $loaders = []): array
    {
        $definitions = [];
        $mirrorNames = self::packageKeys($mirrors);
        $loaderNames = self::packageKeys($loaders);

        foreach ($projections as $package => $configuration) {
            if (! is_string($package) || trim($package) === '') {
                throw new InvalidArgumentException('Projection keys must be package names.');
            }

            $package = strtolower(trim($package));
            if (preg_match(ProjectionDefinition::PACKAGE_PATTERN, $package) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'Projection [%s] must reference a valid Composer package name such as acme/monolith.',
                    $package,
                ));
            }

            if (isset($definitions[$package])) {
                throw new InvalidArgumentException(sprintf('Projection [%s] is configured more than once.', $package));
            }

            if ($package === self::PLUGIN_PACKAGE) {
                throw new InvalidArgumentException('You cannot project the source plugin itself; it must remain fully installed to run.');
            }

            if (isset($mirrorNames[$package])) {
                throw new InvalidArgumentException(sprintf(
                    'Package [%s] cannot be both projected and mirrored: a mirror synchronizes the full package into a working copy, while a projection removes files from the installed package. Configure one or the other.',
                    $package,
                ));
            }

            if (isset($loaderNames[$package])) {
                throw new InvalidArgumentException(sprintf(
                    'Package [%s] cannot be both projected and loadable: a loader chooses a source candidate before resolution, while a projection acts on the installed package after download. Configure one or the other.',
                    $package,
                ));
            }

            if (! is_array($configuration)) {
                throw new InvalidArgumentException(sprintf(
                    'Projection [%s] must map to an array with a surfaces list.',
                    $package,
                ));
            }

            foreach (array_keys($configuration) as $key) {
                if ($key !== 'surfaces') {
                    throw new InvalidArgumentException(sprintf(
                        'Projection [%s] has an unsupported key [%s]; only "surfaces" is accepted.',
                        $package,
                        (string) $key,
                    ));
                }
            }

            $surfaces = $configuration['surfaces'] ?? null;
            if (! is_array($surfaces) || ! array_is_list($surfaces)) {
                throw new InvalidArgumentException(sprintf(
                    'Projection [%s] must select surfaces as a list of surface names.',
                    $package,
                ));
            }

            $selected = [];
            foreach ($surfaces as $surface) {
                if (! is_string($surface) || trim($surface) === '') {
                    throw new InvalidArgumentException(sprintf(
                        'Projection [%s] must use a non-empty surface name.',
                        $package,
                    ));
                }

                $surface = trim($surface);
                if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $surface) !== 1) {
                    throw new InvalidArgumentException(sprintf(
                        'Projection [%s] has an invalid surface name [%s].',
                        $package,
                        $surface,
                    ));
                }

                $selected[$surface] = true;
            }

            if ($selected === []) {
                throw new InvalidArgumentException(sprintf(
                    'Projection [%s] must select at least one surface.',
                    $package,
                ));
            }

            $names = array_keys($selected);
            sort($names);
            $definitions[$package] = new ProjectionDefinition($package, $names);
        }

        ksort($definitions);

        return $definitions;
    }

    /**
     * @param array<string, mixed> $configuration
     * @return array<string, true>
     */
    private static function packageKeys(array $configuration): array
    {
        $names = [];
        foreach (array_keys($configuration) as $package) {
            if (is_string($package)) {
                $names[strtolower(trim($package))] = true;
            }
        }

        return $names;
    }
}
