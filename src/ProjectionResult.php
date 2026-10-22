<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

final class ProjectionResult
{
    /**
     * @param list<string> $removed
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly string $package,
        public readonly bool $changed,
        public readonly int $kept,
        public readonly array $removed = [],
        public readonly array $warnings = [],
    ) {
    }
}
