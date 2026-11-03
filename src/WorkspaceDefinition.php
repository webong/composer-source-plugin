<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

/**
 * One package discovered from `extra.source-plugin.workspaces`.
 *
 * The path is root-relative and uses forward slashes so it is safe to record
 * in committed state and to hand to a Composer path repository.
 */
final class WorkspaceDefinition
{
    /**
     * @param array<string, string> $requires package name => pretty constraint
     */
    public function __construct(
        public readonly string $package,
        public readonly string $path,
        public readonly ?string $version,
        public readonly array $requires = [],
        public readonly bool $symlink = true,
    ) {
    }
}
