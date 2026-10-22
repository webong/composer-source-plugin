<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

final class ProjectionPlan
{
    /**
     * @param list<string> $surfaces
     * @param list<string> $patterns
     * @param list<string> $keep
     * @param list<string> $remove
     * @param array<string, mixed> $marker
     */
    public function __construct(
        public readonly string $package,
        public readonly string $packageRoot,
        public readonly array $surfaces,
        public readonly array $patterns,
        public readonly array $keep,
        public readonly array $remove,
        public readonly string $patternsDigest,
        public readonly string $definitionDigest,
        public readonly ?string $reference,
        public readonly ?string $version,
        public readonly array $marker,
    ) {
    }
}
