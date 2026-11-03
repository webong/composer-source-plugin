<?php

declare(strict_types=1);

namespace Webong\ComposerSource\Symfony;

use Composer\InstalledVersions;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Throwable;

/**
 * Exposes generated compatibility names as Symfony service aliases.
 *
 * class_alias() is enough for ordinary instantiation and autowiring. The
 * container still keys services by string, so a class that is registered
 * under its original name is not reachable through the compatibility name
 * without an explicit alias.
 */
final class ComposerSourceAliasesPass implements CompilerPassInterface
{
    public function __construct(private readonly ?string $aliasesFile = null)
    {
    }

    public function process(ContainerBuilder $container): void
    {
        foreach ($this->aliases($container) as $source => $alias) {
            if (! is_string($source) || ! is_string($alias)) {
                continue;
            }

            if ($source === '' || $alias === '' || $source === $alias) {
                continue;
            }

            // Only alias services that actually exist. An alias to a missing
            // service fails container compilation, and an existing definition
            // or alias belongs to the application, not to this plugin.
            if (! $container->has($source) || $container->has($alias)) {
                continue;
            }

            try {
                $public = $container->findDefinition($source)->isPublic();
            } catch (Throwable) {
                continue;
            }

            $definition = $container->setAlias($alias, $source);

            if ($definition instanceof Alias && $public) {
                $definition->setPublic(true);
            }
        }
    }

    /** @return array<mixed> */
    private function aliases(ContainerBuilder $container): array
    {
        $file = $this->aliasesFile($container);

        if ($file === null || ! is_file($file)) {
            return [];
        }

        $aliases = require $file;

        return is_array($aliases) ? $aliases : [];
    }

    private function aliasesFile(ContainerBuilder $container): ?string
    {
        if ($this->aliasesFile !== null) {
            return $this->aliasesFile;
        }

        try {
            $installPath = InstalledVersions::getInstallPath('webong/composer-source-plugin');
        } catch (Throwable) {
            $installPath = null;
        }

        if (is_string($installPath) && $installPath !== '') {
            // <vendor>/<vendor>/<package> or <vendor>/<package>
            return dirname(rtrim($installPath, '/\\'), 2) . '/composer/source_aliases.php';
        }

        if ($container->hasParameter('kernel.project_dir')) {
            $projectDir = $container->getParameter('kernel.project_dir');

            if (is_string($projectDir) && $projectDir !== '') {
                return rtrim($projectDir, '/\\') . '/vendor/composer/source_aliases.php';
            }
        }

        return null;
    }
}
