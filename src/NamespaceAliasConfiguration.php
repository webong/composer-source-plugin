<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

use InvalidArgumentException;

final class NamespaceAliasConfiguration
{
    /**
     * @param array<string, mixed> $aliases
     * @return list<NamespaceAliasDefinition>
     */
    public static function parse(array $aliases): array
    {
        $definitions = [];
        $configuredPrefixes = [];

        foreach ($aliases as $key => $value) {
            if (is_string($value)) {
                $definitions[] = self::definition(
                    sourcePrefix: $key,
                    targetPrefix: $value,
                    configuredPrefixes: $configuredPrefixes,
                );

                continue;
            }

            if (! is_array($value)) {
                throw new InvalidArgumentException(sprintf(
                    'Namespace alias [%s] must map to a namespace prefix or package configuration.',
                    $key,
                ));
            }

            $type = $value['type'] ?? NamespaceAliasDefinition::TYPE_SIMPLE;
            if (! is_string($type) || ! in_array($type, [NamespaceAliasDefinition::TYPE_SIMPLE, NamespaceAliasDefinition::TYPE_REBASE], true)) {
                throw new InvalidArgumentException(sprintf(
                    'Namespace alias package [%s] has an unsupported type.',
                    $key,
                ));
            }

            $copy = $value['copy'] ?? 'package';
            if (! in_array($copy, ['package', 'autoload'], true)) {
                throw new InvalidArgumentException('Rebase copy must be package or autoload.');
            }
            $include = self::paths($value['include'] ?? []);
            $exclude = self::paths($value['exclude'] ?? []);
            $destination = $value['destination'] ?? NamespaceAliasDefinition::DESTINATION_GENERATED;
            if (! in_array($destination, [NamespaceAliasDefinition::DESTINATION_GENERATED, NamespaceAliasDefinition::DESTINATION_MIRROR], true)) {
                throw new InvalidArgumentException('Rebase destination must be generated or mirror.');
            }
            if ($type !== NamespaceAliasDefinition::TYPE_REBASE && ($copy !== 'package' || $include !== [] || $exclude !== [])) {
                throw new InvalidArgumentException('Copy options require a rebase alias.');
            }
            $hasNamespace = false;
            foreach ($value as $sourcePrefix => $targetPrefix) {
                if (in_array($sourcePrefix, ['type', 'copy', 'include', 'exclude', 'destination'], true)) {
                    continue;
                }

                if (! is_string($targetPrefix)) {
                    throw new InvalidArgumentException(sprintf(
                        'Namespace alias [%s] for package [%s] must map to a namespace prefix.',
                        $sourcePrefix,
                        $key,
                    ));
                }

                $definitions[] = self::definition(
                    sourcePrefix: $sourcePrefix,
                    targetPrefix: $targetPrefix,
                    type: $type,
                    package: $key,
                    configuredPrefixes: $configuredPrefixes,
                    copy: $copy,
                    include: $include,
                    exclude: $exclude,
                    destination: $destination,
                );
                $hasNamespace = true;
            }

            if (! $hasNamespace) {
                throw new InvalidArgumentException(sprintf(
                    'Namespace alias package [%s] must define at least one namespace prefix.',
                    $key,
                ));
            }
        }

        return $definitions;
    }

    /** @param array<string, true> $configuredPrefixes */
    private static function definition(
        string $sourcePrefix,
        string $targetPrefix,
        array &$configuredPrefixes,
        string $type = NamespaceAliasDefinition::TYPE_SIMPLE,
        ?string $package = null,
        string $copy = 'package',
        array $include = [],
        array $exclude = [],
        string $destination = NamespaceAliasDefinition::DESTINATION_GENERATED,
    ): NamespaceAliasDefinition {
        if ($sourcePrefix === '' || $targetPrefix === '') {
            throw new InvalidArgumentException('Namespace alias prefixes cannot be empty.');
        }

        if (isset($configuredPrefixes[$sourcePrefix])) {
            throw new InvalidArgumentException(sprintf(
                'Namespace alias prefix [%s] is configured more than once.',
                $sourcePrefix,
            ));
        }

        $configuredPrefixes[$sourcePrefix] = true;

        return new NamespaceAliasDefinition($sourcePrefix, $targetPrefix, $type, $package, $copy, $include, $exclude, $destination);
    }

    private static function paths(mixed $paths): array
    {
        if (! is_array($paths) || ! array_is_list($paths)) {
            throw new InvalidArgumentException('Rebase include/exclude must be lists of package-relative paths.');
        }
        foreach ($paths as $path) {
            if (! is_string($path) || $path === '' || str_contains($path, '\\') || str_contains($path, ':') || str_starts_with($path, '/') || in_array('..', explode('/', $path), true)) {
                throw new InvalidArgumentException('Rebase paths must stay inside the package.');
            }
        }
        return $paths;
    }
}
