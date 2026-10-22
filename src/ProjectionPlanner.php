<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

use InvalidArgumentException;
use RuntimeException;

final class ProjectionPlanner
{
    public const MANDATORY_FILES = ['composer.json'];

    /** @param array<string, SurfaceDefinition> $surfaces */
    public function __construct(
        private readonly string $package,
        private readonly string $packageRoot,
        private readonly array $surfaces,
    ) {
    }

    /**
     * Resolve the complete allowlist before anything on disk is mutated.
     *
     * @param list<string> $selectedSurfaces
     * @param array<string, mixed>|null $previousMarker
     */
    public function plan(
        array $selectedSurfaces,
        ?array $previousMarker = null,
        bool $freshInstall = false,
        ?string $reference = null,
        ?string $version = null,
    ): ProjectionPlan {
        $selected = [];
        foreach ($selectedSurfaces as $name) {
            if (! is_string($name) || trim($name) === '') {
                throw new InvalidArgumentException('Projected surfaces must be non-empty names.');
            }
            $selected[trim($name)] = true;
        }
        if ($selected === []) {
            throw new InvalidArgumentException(sprintf(
                'Projection [%s] must select at least one surface.',
                $this->package,
            ));
        }

        foreach (array_keys($selected) as $name) {
            if (! isset($this->surfaces[$name])) {
                $available = implode(', ', array_keys($this->surfaces));
                throw new InvalidArgumentException(sprintf(
                    'Projection [%s] selects unknown surface [%s]; available surfaces: %s.',
                    $this->package,
                    $name,
                    $available === '' ? '(none)' : $available,
                ));
            }
        }

        $surfaces = array_keys($selected);
        sort($surfaces);

        if ($previousMarker !== null && ($previousMarker['package'] ?? null) !== $this->package) {
            throw new InvalidArgumentException(sprintf(
                'Projection marker for [%s] belongs to a different package.',
                $this->package,
            ));
        }

        $patterns = self::MANDATORY_FILES;
        foreach ($surfaces as $name) {
            $patterns = array_merge($patterns, $this->surfaces[$name]->include);
        }
        $patterns = array_values(array_unique($patterns));
        sort($patterns);

        $this->assertWideningIsMaterializable($surfaces, $previousMarker, $freshInstall);

        $files = PackageFileInventory::files($this->packageRoot);
        foreach (self::MANDATORY_FILES as $mandatory) {
            if (! in_array($mandatory, $files, true)) {
                throw new RuntimeException(sprintf(
                    'Installed package [%s] is missing its mandatory file [%s].',
                    $this->package,
                    $mandatory,
                ));
            }
        }

        $keep = [];
        $remove = [];
        foreach ($files as $file) {
            if (IncludePattern::matchesAny($patterns, $file)) {
                $keep[] = $file;
            } else {
                $remove[] = $file;
            }
        }

        foreach (self::MANDATORY_FILES as $mandatory) {
            if (! in_array($mandatory, $keep, true)) {
                $keep[] = $mandatory;
                $remove = array_values(array_filter(
                    $remove,
                    static fn (string $path): bool => $path !== $mandatory,
                ));
            }
        }

        sort($keep);
        sort($remove);

        $previousRemoved = $previousMarker['removed'] ?? [];
        if (! is_array($previousRemoved)) {
            throw new RuntimeException('Invalid projection marker: removed must be a list.');
        }
        if ($freshInstall) {
            // Composer extracted a complete archive, so previous removals no
            // longer constrain widening and any stale history is discarded.
            $previousRemoved = [];
        }

        $removed = array_values(array_unique(array_merge($previousRemoved, $remove)));
        sort($removed);

        $patternsDigest = hash('sha256', implode("\n", $patterns));
        $definitionDigest = SurfaceConfiguration::digest($this->surfaces);

        $marker = ProjectionMarker::create(
            $this->package,
            $reference,
            $version,
            $surfaces,
            $patterns,
            $patternsDigest,
            $definitionDigest,
            $removed,
        );

        return new ProjectionPlan(
            package: $this->package,
            packageRoot: PackageFileInventory::root($this->packageRoot),
            surfaces: $surfaces,
            patterns: $patterns,
            keep: $keep,
            remove: $remove,
            patternsDigest: $patternsDigest,
            definitionDigest: $definitionDigest,
            reference: $reference,
            version: $version,
            marker: $marker,
        );
    }

    /**
     * Widen only when the newly selected surfaces can still be materialized.
     * A removed file matching a newly selected surface means the package was
     * projected earlier and a reinstall is required to restore the archive.
     *
     * @param list<string> $surfaces
     * @param array<string, mixed>|null $previousMarker
     */
    private function assertWideningIsMaterializable(array $surfaces, ?array $previousMarker, bool $freshInstall): void
    {
        if ($freshInstall || $previousMarker === null) {
            return;
        }

        $previous = $previousMarker['surfaces'] ?? [];
        if (! is_array($previous)) {
            throw new RuntimeException('Invalid projection marker: surfaces must be a list.');
        }

        $newlySelected = array_values(array_diff($surfaces, $previous));
        if ($newlySelected === []) {
            return;
        }

        $removed = $previousMarker['removed'] ?? [];
        if (! is_array($removed) || $removed === []) {
            return;
        }

        $includes = [];
        foreach ($newlySelected as $name) {
            $includes = array_merge($includes, $this->surfaces[$name]->include);
        }

        $missing = [];
        foreach ($removed as $path) {
            if (! is_string($path) || $path === '') {
                throw new RuntimeException('Invalid projection marker: removed must contain relative paths.');
            }
            if (IncludePattern::matchesAny($includes, $path)) {
                $missing[] = $path;
            }
        }

        if ($missing !== []) {
            sort($missing);
            throw new RuntimeException(sprintf(
                'Cannot widen projection of [%s]: %d previously removed file(s) are needed by the newly selected surface(s) [%s], including [%s]. Reinstall the package to restore the full archive: composer reinstall %s',
                $this->package,
                count($missing),
                implode(', ', $newlySelected),
                $missing[0],
                $this->package,
            ));
        }
    }
}
