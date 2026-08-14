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

            $hasNamespace = false;
            foreach ($value as $sourcePrefix => $targetPrefix) {
                if ($sourcePrefix === 'type') {
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

        return new NamespaceAliasDefinition($sourcePrefix, $targetPrefix, $type, $package);
    }
}
