<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

use InvalidArgumentException;

/** Discover publication inputs from committed manifests, never a dirty checkout. */
final class PublicationDiscovery
{
    /** @return list<PublicationPackage> */
    public static function discover(PublicationGit $git, string $commit): array
    {
        $root = sys_get_temp_dir().'/source-publication-'.bin2hex(random_bytes(8));
        if (! mkdir($root, 0700)) {
            throw new PublicationException('Cannot prepare committed workspace discovery.');
        }
        try {
            $manifest = json_decode($git->out(['show', $commit.':composer.json']), true, 512, JSON_THROW_ON_ERROR);
            $workspaces = $manifest['extra']['source-plugin']['workspaces'] ?? [];
            if (! is_array($workspaces) || $workspaces === []) {
                throw new PublicationException('Declare publication packages under extra.source-plugin.workspaces.');
            }
            $listing = $git->out(['ls-tree', '-r', '-z', $commit]);
            $count = 0;
            $links = [];
            foreach (explode("\0", $listing) as $entry) {
                if ($entry === '') {
                    continue;
                }
                [$header, $path] = explode("\t", $entry, 2);
                if (str_starts_with($header, '120000 ') || str_starts_with($header, '160000 ')) {
                    $links[] = $path;
                }
                if (! str_ends_with($path, '/composer.json')) {
                    continue;
                }
                if (! SourceLock::isSafeRelativePath($path) || preg_match('/[\x00-\x1f\x7f]/', $path)) {
                    throw new PublicationException('Unsafe workspace manifest path.');
                }
                if (! str_starts_with($header, '100644 blob ') && ! str_starts_with($header, '100755 blob ')) {
                    throw new PublicationException('Workspace manifests must be regular files: '.$path);
                }
                if (++$count > 10000) {
                    throw new PublicationException('Publication exceeds 10000 manifests.');
                }
                mkdir(dirname($root.'/'.$path), 0700, true);
                file_put_contents($root.'/'.$path, $git->out(['show', $commit.':'.$path]));
            }
            $packages = [];
            $paths = [];
            foreach (WorkspaceConfiguration::parse($workspaces, $root) as $workspace) {
                $data = json_decode((string) file_get_contents($root.'/'.$workspace->path.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
                if (($data['type'] ?? 'library') === 'project') {
                    continue;
                }
                if (($data['type'] ?? 'library') !== 'library' && ($data['type'] ?? '') !== 'metapackage') {
                    throw new PublicationException('Initial publication supports library and metapackage packages only.');
                }
                foreach ($paths as $path) {
                    if (str_starts_with($workspace->path.'/', $path.'/') || str_starts_with($path.'/', $workspace->path.'/')) {
                        throw new PublicationException('Publication package roots must not overlap.');
                    }
                }
                foreach ($links as $link) {
                    if (str_starts_with($link, $workspace->path.'/')) {
                        throw new PublicationException('Publication package contains a symlink or submodule: '.$link);
                    }
                }
                PublicationManifest::validate($data);
                $paths[] = $workspace->path;
                $packages[] = new PublicationPackage($workspace->package, $workspace->path, $data);
            }
            if ($packages === []) {
                throw new PublicationException('No publishable workspace packages found.');
            }
            return $packages;
        } catch (InvalidArgumentException $error) {
            throw new PublicationException($error->getMessage(), 0, $error);
        } finally {
            self::remove($root);
        }
    }

    private static function remove(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory.'/'.$entry;
            if (is_dir($path) && ! is_link($path)) {
                self::remove($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
