<?php

declare(strict_types=1);

namespace Webong\ComposerNamespaceAlias;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;

final class PackageSourceSelector
{
    private const EXTRA_KEY = 'composer-namespace-alias';
    private const SOURCES_KEY = 'sources';
    private const LOCAL = 'local';
    private const EXTERNAL = 'external';
    private const AUTO = 'auto';

    public function __construct(
        private readonly Composer $composer,
        private readonly IOInterface $io,
    ) {
    }

    public function select(object $event): void
    {
        $sources = $this->sourceConfiguration();
        if ($sources === [] || ! method_exists($event, 'getPackages') || ! method_exists($event, 'setPackages')) {
            return;
        }

        $packages = $event->getPackages();
        $selected = [];

        foreach ($sources as $packageName => $configuration) {
            if (! is_array($configuration)) {
                continue;
            }

            $preference = $this->preference($configuration);

            $candidates = array_values(array_filter(
                $packages,
                static fn (mixed $package): bool => $package instanceof PackageInterface
                    && $package->getPrettyName() === $packageName,
            ));

            if ($candidates === []) {
                continue;
            }

            if (($preference === self::LOCAL || ($preference === self::AUTO && $this->localManifestExists($configuration)))
                && ! $this->hasPathCandidate($candidates)) {
                foreach ($candidates as $candidate) {
                    $selected[spl_object_id($candidate)] = true;
                }
                $this->removeRootRequirement($packageName);
                continue;
            }

            $preference = $this->preference($configuration);
            $preferred = $this->preferredCandidate($candidates, $preference);

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
                $preference,
                $packageName,
            ));

            if ($preference === self::EXTERNAL) {
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
    private function sourceConfiguration(): array
    {
        $extra = $this->composer->getPackage()->getExtra()[self::EXTRA_KEY] ?? [];
        $sources = is_array($extra) ? ($extra[self::SOURCES_KEY] ?? []) : [];

        return is_array($sources) ? $sources : [];
    }

    /** @param array<string, mixed> $configuration */
    private function preference(array $configuration): string
    {
        $preference = $configuration['preference'] ?? self::AUTO;

        return in_array($preference, [self::LOCAL, self::EXTERNAL, self::AUTO], true)
            ? $preference
            : self::AUTO;
    }

    /** @param list<PackageInterface> $candidates */
    private function preferredCandidate(array $candidates, string $preference): ?PackageInterface
    {
        $pathCandidates = array_values(array_filter(
            $candidates,
            static fn (PackageInterface $package): bool => $package->getDistType() === 'path',
        ));
        $externalCandidates = array_values(array_filter(
            $candidates,
            static fn (PackageInterface $package): bool => $package->getDistType() !== 'path',
        ));

        return match ($preference) {
            self::LOCAL => $pathCandidates[0] ?? null,
            self::EXTERNAL => $externalCandidates[0] ?? null,
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
        $manifest = $configuration['local_manifest'] ?? null;

        return is_string($manifest) && $manifest !== '' && is_file(getcwd() . '/' . $manifest);
    }

    /** @param array<string, mixed> $configuration */
    private function removeMergedLocalAutoload(array $configuration): void
    {
        $localPath = $configuration['local_path'] ?? null;
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
        $manifest = $configuration['local_manifest'] ?? null;
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
