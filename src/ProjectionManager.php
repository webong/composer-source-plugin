<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use RuntimeException;

final class ProjectionManager
{
    public function __construct(
        private readonly Composer $composer,
        private readonly IOInterface $io,
        private readonly bool $updating = false,
        private readonly bool $reinstalling = false,
    ) {
    }

    public function projectPackage(PackageInterface $package): void
    {
        $definitions = $this->definitions();
        $name = strtolower($package->getName());
        if (! isset($definitions[$name])) {
            return;
        }

        $this->projectOne($definitions[$name], $package, true, $this->canWriteState());
    }

    /**
     * @param array<string, true> $freshPackages
     */
    public function reconcile(array $freshPackages = [], ?bool $writeState = null): void
    {
        $definitions = $this->definitions();
        if ($definitions === []) {
            return;
        }

        $writeState = $writeState ?? $this->canWriteState();
        $installed = $this->installedPackages();

        foreach ($definitions as $name => $definition) {
            $package = $installed[$name] ?? null;
            if (! $package instanceof PackageInterface) {
                throw new RuntimeException(sprintf(
                    'Projection [%s] is configured, but the package is not installed. Install it or remove the projection.',
                    $name,
                ));
            }

            $this->projectOne($definition, $package, isset($freshPackages[$name]), $writeState);
        }
    }

    public function canWriteState(): bool
    {
        return $this->updating || $this->reinstalling;
    }

    /** @return array<string, ProjectionDefinition> */
    private function definitions(): array
    {
        return ProjectionConfiguration::parse(
            SourcePluginConfig::projections($this->composer),
            SourcePluginConfig::mirrors($this->composer),
            SourcePluginConfig::loaders($this->composer),
        );
    }

    /** @return array<string, PackageInterface> */
    private function installedPackages(): array
    {
        $packages = [];
        $localRepository = $this->composer->getRepositoryManager()->getLocalRepository();

        foreach ($localRepository->getCanonicalPackages() as $package) {
            $packages[strtolower($package->getName())] = $package;
        }

        return $packages;
    }

    private function projectOne(
        ProjectionDefinition $definition,
        PackageInterface $package,
        bool $freshInstall,
        bool $writeState,
    ): void {
        $installPath = $this->installPath($package);
        $surfaces = SurfaceConfiguration::fromPackageRoot($installPath);
        if ($surfaces === []) {
            throw new RuntimeException(sprintf(
                'Package [%s] does not declare any surfaces under extra.source-plugin.surfaces and cannot be projected.',
                $definition->package,
            ));
        }

        $marker = ProjectionMarker::read($installPath);
        $plan = (new ProjectionPlanner(
            $definition->package,
            $installPath,
            $surfaces,
        ))->plan(
            selectedSurfaces: $definition->surfaces,
            previousMarker: $marker,
            freshInstall: $freshInstall,
            reference: $this->reference($package),
            version: $package->getPrettyVersion(),
        );

        $stateEntry = $this->stateEntry($plan);
        if (! $writeState) {
            $this->assertMatchesState($definition->package, $plan, $stateEntry);
        }

        $result = (new PackageProjector($installPath))->project($plan);

        // Reinstall bypasses pre-autoload-dump entirely, so keep the in-memory
        // autoload metadata safe immediately after projecting.
        ProjectionAutoloadFilter::filter($package, $installPath, $this->io);

        if ($writeState) {
            ProjectionState::save($this->lockFile(), $definition->package, $stateEntry);
        }

        $this->report($definition, $result);
    }

    /** @param array<string, mixed> $expected */
    private function assertMatchesState(string $package, ProjectionPlan $plan, array $expected): void
    {
        $state = ProjectionState::entry($this->lockFile(), $package);
        if ($state === null) {
            throw new RuntimeException(sprintf(
                'No projection state for [%s] exists in %s. Run composer update to record the projection before installing from a lock.',
                $package,
                $this->lockFile(),
            ));
        }

        foreach (['format', 'reference', 'version', 'surfaces', 'patterns', 'patterns_digest', 'definition_digest'] as $key) {
            if (($state[$key] ?? null) === ($expected[$key] ?? null)) {
                continue;
            }

            throw new RuntimeException(sprintf(
                'Projection state for [%s] does not match %s (%s differs). Run composer update to advance the committed projection state, or restore the committed configuration.',
                $package,
                $this->lockFile(),
                $key,
            ));
        }
    }

    /** @return array<string, mixed> */
    private function stateEntry(ProjectionPlan $plan): array
    {
        return [
            'format' => ProjectionState::FORMAT,
            'reference' => $plan->reference,
            'version' => $plan->version,
            'surfaces' => $plan->surfaces,
            'patterns' => $plan->patterns,
            'patterns_digest' => $plan->patternsDigest,
            'definition_digest' => $plan->definitionDigest,
        ];
    }

    private function installPath(PackageInterface $package): string
    {
        $path = $this->composer->getInstallationManager()->getInstallPath($package);
        if (! is_string($path) || ! is_dir($path)) {
            throw new RuntimeException(sprintf(
                'Projection [%s] cannot run because the installed package path is missing.',
                $package->getPrettyName(),
            ));
        }

        return $path;
    }

    private function reference(PackageInterface $package): ?string
    {
        $source = $package->getSourceReference();
        if (is_string($source) && $source !== '') {
            return $source;
        }

        $dist = $package->getDistReference();
        if (is_string($dist) && $dist !== '') {
            return $dist;
        }

        // Artifact and some custom repositories ship only a dist checksum.
        // A dist URL is deliberately not used: it can be machine-specific,
        // while the lock state must stay portable.
        $checksum = method_exists($package, 'getDistSha1Checksum') ? $package->getDistSha1Checksum() : null;

        return is_string($checksum) && $checksum !== '' ? $checksum : null;
    }

    private function lockFile(): string
    {
        return SourceLock::path();
    }

    private function report(ProjectionDefinition $definition, ProjectionResult $result): void
    {
        foreach ($result->warnings as $warning) {
            $this->io->writeError(sprintf('<warning>%s: %s</warning>', $definition->package, $warning));
        }

        if (! $result->changed) {
            return;
        }

        $this->io->writeError(sprintf(
            '<info>Projected %s to surfaces [%s]: kept %d file(s), removed %d.</info>',
            $definition->package,
            implode(', ', $definition->surfaces),
            $result->kept,
            count($result->removed),
        ));
    }
}
