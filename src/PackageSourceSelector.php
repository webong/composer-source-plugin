<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;

final class PackageSourceSelector
{
    private const EXTRA_KEY = 'composer-source';
    private const LOADERS_KEY = 'loaders';
    private const INLINE = 'inline';
    private const OUTLINE = 'outline';
    private const AUTO = 'auto';

    public function __construct(
        private readonly Composer $composer,
        private readonly IOInterface $io,
    ) {
    }

    public function select(object $event): void
    {
        $loaders = $this->loaderConfiguration();
        if ($loaders === [] || ! method_exists($event, 'getPackages') || ! method_exists($event, 'setPackages')) {
            return;
        }

        $packages = $event->getPackages();
        $selected = [];

        foreach ($loaders as $packageName => $configuration) {
            if (! is_array($configuration)) {
                continue;
            }

            $type = $this->loaderType($configuration);

            $candidates = array_values(array_filter(
                $packages,
                static fn (mixed $package): bool => $package instanceof PackageInterface
                    && $package->getPrettyName() === $packageName,
            ));

            if ($candidates === []) {
                continue;
            }

            if (($type === self::INLINE || ($type === self::AUTO && $this->localManifestExists($configuration)))
                && ! $this->hasPathCandidate($candidates)) {
                foreach ($candidates as $candidate) {
                    $selected[spl_object_id($candidate)] = true;
                }
                $this->removeRootRequirement($packageName);
                continue;
            }

            $type = $this->loaderType($configuration);
            $preferred = $this->preferredCandidate($candidates, $type);

            if ($preferred === null) {
                foreach ($candidates as $candidate) {
                    $selected[spl_object_id($candidate)] = true;
                }

                continue;
            }

            foreach ($candidates as $candidate) {
                if ($candidate !== $preferred) {
                    $selected[spl_object_id($candidate)] = true;
                }
            }

            $this->io->writeError(sprintf(
                '<info>%s source selected for %s.</info>',
                $type,
                $packageName,
            ));

            if ($type === self::OUTLINE) {
                $this->removeMergedLocalAutoload($configuration);
                $this->removeMergedLocalManifest($configuration);
            }
        }

        if ($selected === []) {
            return;
        }

        $event->setPackages(array_values(array_filter(
            $packages,
            static fn (mixed $package): bool => ! ($package instanceof PackageInterface)
                || ! isset($selected[spl_object_id($package)]),
        )));
    }

    /** @return array<string, array<string, mixed>> */
    private function loaderConfiguration(): array
    {
        $extra = $this->composer->getPackage()->getExtra()[self::EXTRA_KEY] ?? [];
        $loaders = is_array($extra) ? ($extra[self::LOADERS_KEY] ?? []) : [];

        return is_array($loaders) ? $loaders : [];
    }

    /** @param array<string, mixed> $configuration */
    private function loaderType(array $configuration): string
    {
        $type = $configuration['type'] ?? self::AUTO;

        return in_array($type, [self::INLINE, self::OUTLINE, self::AUTO], true)
            ? $type
            : self::AUTO;
    }

    /** @param list<PackageInterface> $candidates */
    private function preferredCandidate(array $candidates, string $type): ?PackageInterface
    {
        $pathCandidates = array_values(array_filter(
            $candidates,
            static fn (PackageInterface $package): bool => $package->getDistType() === 'path',
        ));
        $externalCandidates = array_values(array_filter(
            $candidates,
            static fn (PackageInterface $package): bool => $package->getDistType() !== 'path',
        ));

        return match ($type) {
            self::INLINE => $pathCandidates[0] ?? null,
            self::OUTLINE => $externalCandidates[0] ?? null,
            default => $pathCandidates[0] ?? $externalCandidates[0] ?? null,
        };
    }

    /** @param list<PackageInterface> $candidates */
    private function hasPathCandidate(array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if ($candidate->getDistType() === 'path') {
                return true;
            }
        }

        return false;
    }

    private function removeRootRequirement(string $packageName): void
    {
        $root = $this->composer->getPackage();
        $requires = $root->getRequires();
        unset($requires[$packageName]);
        $root->setRequires($requires);
    }

    /** @param array<string, mixed> $configuration */
    private function localManifestExists(array $configuration): bool
    {
        $manifest = $configuration['manifest'] ?? null;

        return is_string($manifest) && $manifest !== '' && is_file(getcwd() . '/' . $manifest);
    }

    /** @param array<string, mixed> $configuration */
    private function removeMergedLocalAutoload(array $configuration): void
    {
        $localPath = $configuration['path'] ?? null;
        $root = $this->composer->getPackage();

        if (! is_string($localPath) || $localPath === ''
            || ! method_exists($root, 'getAutoload')
            || ! method_exists($root, 'setAutoload')) {
            return;
        }

        $absolutePath = realpath(getcwd() . '/' . $localPath);
        if ($absolutePath === false) {
            return;
        }

        $autoload = $root->getAutoload();
        foreach (['psr-4', 'psr-0'] as $type) {
            foreach ($autoload[$type] ?? [] as $prefix => $paths) {
                $paths = is_array($paths) ? $paths : [$paths];
                $paths = array_values(array_filter(
                    $paths,
                    static function (mixed $path) use ($absolutePath): bool {
                        if (! is_string($path)) {
                            return true;
                        }

                        $resolved = realpath(getcwd() . '/' . $path);

                        return $resolved === false || ! str_starts_with($resolved, $absolutePath);
                    },
                ));

                if ($paths === []) {
                    unset($autoload[$type][$prefix]);
                } else {
                    $autoload[$type][$prefix] = $paths;
                }
            }
        }

        $root->setAutoload($autoload);
    }

    /** @param array<string, mixed> $configuration */
    private function removeMergedLocalManifest(array $configuration): void
    {
        $manifest = $configuration['manifest'] ?? null;
        if (! is_string($manifest) || $manifest === '') {
            return;
        }

        $path = realpath(getcwd() . '/' . $manifest);
        if ($path === false) {
            return;
        }

        $contents = file_get_contents($path);
        $local = is_string($contents) ? json_decode($contents, true) : null;
        if (! is_array($local)) {
            return;
        }

        $root = $this->composer->getPackage();
        $requires = $root->getRequires();
        foreach (array_keys(is_array($local['require'] ?? null) ? $local['require'] : []) as $dependency) {
            if ($dependency !== 'php' && $dependency !== 'composer-plugin-api') {
                unset($requires[$dependency]);
            }
        }
        $root->setRequires($requires);
    }
}
