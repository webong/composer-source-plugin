<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;

/**
 * Drops autoload entries that point at files removed by a projection.
 *
 * This only filters entries that are already missing from disk. It never
 * fabricates optional module autoloading; package authors still have to route
 * optional bootstrap files through a retained dispatcher.
 */
final class ProjectionAutoloadFilter
{
    public static function filter(PackageInterface $package, string $packageRoot, IOInterface $io): void
    {
        if (! method_exists($package, 'getAutoload') || ! method_exists($package, 'setAutoload')) {
            return;
        }

        $autoload = $package->getAutoload();
        $changed = false;

        foreach (['files', 'classmap', 'exclude-from-classmap'] as $type) {
            if (! is_array($autoload[$type] ?? null)) {
                continue;
            }

            foreach ($autoload[$type] as $key => $entry) {
                if (! is_string($entry) || $entry === '' || strpbrk($entry, '*?[') !== false) {
                    continue;
                }

                $target = rtrim($packageRoot, '/\\') . '/' . ltrim(str_replace('\\', '/', $entry), '/');
                if (file_exists($target)) {
                    continue;
                }

                unset($autoload[$type][$key]);
                $changed = true;
                $io->writeError(sprintf(
                    '<warning>%s: removed autoload entry [%s] because projection deleted it.</warning>',
                    $package->getPrettyName(),
                    $entry,
                ), true, IOInterface::VERBOSE);
            }

            if (is_array($autoload[$type] ?? null)) {
                $autoload[$type] = array_values($autoload[$type]);
            }
        }

        if ($changed) {
            $package->setAutoload($autoload);
        }
    }
}
