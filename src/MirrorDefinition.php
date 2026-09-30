<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

use InvalidArgumentException;

final class MirrorDefinition
{
    public const ORIGIN_REMOTE = 'remote';

    public readonly string $origin;

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(
        public readonly string $package,
        public readonly string $path,
        string $origin = self::ORIGIN_REMOTE,
    ) {
        if (trim($package) === '') {
            throw new InvalidArgumentException('Mirror package name cannot be empty.');
        }

        if (trim($path) === '') {
            throw new InvalidArgumentException(sprintf(
                'Mirror [%s] must declare a path.',
                $package,
            ));
        }

        if ($origin !== self::ORIGIN_REMOTE) {
            throw new InvalidArgumentException(sprintf(
                'Mirror [%s] has an unsupported origin [%s].',
                $package,
                $origin,
            ));
        }

        $this->origin = $origin;
    }
}
