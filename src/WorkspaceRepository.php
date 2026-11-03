<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Repository\PathRepository;

/**
 * Turns discovered workspaces into canonical path repositories.
 *
 * Composer resolves a package name from the first canonical repository that
 * provides it, so prepending these repositories makes the local copy win over
 * Packagist or a VCS repository without touching the consumer's composer.json.
 * Passing the path relatively keeps `dist.url` in composer.lock machine-neutral.
 */
final class WorkspaceRepository
{
    /**
     * @param array<string, WorkspaceDefinition> $workspaces
     */
    public static function prepend(
        Composer $composer,
        IOInterface $io,
        array $workspaces,
    ): void {
        if ($workspaces === []) {
            return;
        }

        $manager = $composer->getRepositoryManager();

        // prependRepository() inserts at index 0, so walk in reverse to keep
        // the declaration order of the workspaces list.
        foreach (array_reverse($workspaces, true) as $workspace) {
            $manager->prependRepository(new PathRepository(
                [
                    'type' => 'path',
                    'url' => $workspace->path,
                    'options' => ['symlink' => $workspace->symlink],
                ],
                $io,
                $composer->getConfig(),
            ));
        }
    }
}
