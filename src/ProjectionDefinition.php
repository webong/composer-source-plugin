<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

use InvalidArgumentException;

final class ProjectionDefinition
{
    public const PACKAGE_PATTERN = '#^[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._-]*$#D';

    /** @param list<string> $surfaces */
    public function __construct(
        public readonly string $package,
        public readonly array $surfaces,
    ) {
        if (preg_match(self::PACKAGE_PATTERN, $package) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Projection [%s] must reference a valid Composer package name such as acme/monolith.',
                $package,
            ));
        }

        if ($surfaces === []) {
            throw new InvalidArgumentException(sprintf(
                'Projection [%s] must select at least one surface.',
                $package,
            ));
        }
    }
}
