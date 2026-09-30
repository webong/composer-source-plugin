<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

use Composer\Composer;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Throwable;

final class PackageMirrorSynchroniser
{
    public function __construct(
        private readonly Composer $composer,
        private readonly IOInterface $io,
        private readonly bool $updating = false,
    ) {
    }

    public function sync(): void
    {
        $definitions = $this->definitions();
        if ($definitions === []) {
            return;
        }

        $installed = $this->installedPackages();

        foreach ($definitions as $definition) {
            $package = $installed[$definition->package] ?? null;
            if (! $package instanceof PackageInterface) {
                continue;
            }

            $this->syncOne($definition, $package);
        }
    }

    /** @return list<MirrorDefinition> */
    private function definitions(): array
    {
        return MirrorConfiguration::parse(
            SourcePluginConfig::mirrors($this->composer),
            SourcePluginConfig::loaders($this->composer),
        );
    }

    /** @return array<string, PackageInterface> */
    private function installedPackages(): array
    {
        $packages = [];

        foreach ($this->composer->getRepositoryManager()->getLocalRepository()->getPackages() as $package) {
            $packages[$package->getName()] = $package;
        }

        return $packages;
    }

    private function syncOne(MirrorDefinition $definition, PackageInterface $package): void
    {
        $installPath = $this->composer->getInstallationManager()->getInstallPath($package);
        if (! is_string($installPath) || ! is_dir($installPath)) {
            return;
        }

        $mirrorRoot = $this->resolveMirrorRoot($definition);

        try {
            $result = (new SourceMirror(
                sourceRoot: $installPath,
                mirrorRoot: $mirrorRoot,
                package: $definition->package,
                rebases: $this->mirrorRebases($definition->package),
                stateFile: $this->stateFile(),
                writeState: $this->stateFile() === null || $this->updating,
            ))->sync($this->reference($package));
        } catch (Throwable $exception) {
            // A mirror problem must never abort the install.
            $this->io->writeError(sprintf(
                '<warning>Could not mirror %s into %s: %s</warning>',
                $definition->package,
                $definition->path,
                $exception->getMessage(),
            ));

            return;
        }

        $this->report($definition, $result);
    }

    /** @return list<NamespaceAliasDefinition> */
    private function mirrorRebases(string $package): array
    {
        return array_values(array_filter(
            NamespaceAliasConfiguration::parse(SourcePluginConfig::aliases($this->composer)),
            static fn (NamespaceAliasDefinition $definition): bool => $definition->package === $package
                && $definition->type === NamespaceAliasDefinition::TYPE_REBASE
                && $definition->destination === NamespaceAliasDefinition::DESTINATION_MIRROR,
        ));
    }

    private function stateFile(): ?string
    {
        if ((SourcePluginConfig::section($this->composer)['mirror-state'] ?? 'local') !== 'lock') {
            return null;
        }
        $manifest = Factory::getComposerFile();
        return dirname($manifest) . '/' . pathinfo($manifest, PATHINFO_FILENAME) . '-source.lock';
    }

    private function report(MirrorDefinition $definition, MirrorResult $result): void
    {
        foreach ($result->warnings as $warning) {
            $this->io->writeError(sprintf('<warning>%s: %s</warning>', $definition->package, $warning));
        }

        foreach ($result->conflicts as $path) {
            $this->io->writeError(sprintf(
                '<warning>%s: %s conflicts with upstream; kept local copy, upstream saved as %s.upstream</warning>',
                $definition->package,
                $path,
                $path,
            ));
        }

        if (! $result->hasChanges() && $result->conflicts === []) {
            return;
        }

        $this->io->writeError(sprintf(
            '<info>Mirrored %s into %s (%d written, %d removed, %d conflicting).</info>',
            $definition->package,
            $definition->path,
            count($result->written),
            count($result->removed),
            count($result->conflicts),
        ));
    }

    private function resolveMirrorRoot(MirrorDefinition $definition): string
    {
        $path = str_replace('\\', '/', $definition->path);

        if (str_starts_with($path, '/')) {
            return $path;
        }

        return rtrim((string) getcwd(), '/') . '/' . ltrim($path, '/');
    }

    private function reference(PackageInterface $package): ?string
    {
        $source = $package->getSourceReference();
        if (is_string($source) && $source !== '') {
            return $source;
        }

        $dist = $package->getDistReference();

        return is_string($dist) && $dist !== '' ? $dist : null;
    }
}
