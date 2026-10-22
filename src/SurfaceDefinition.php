<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

use InvalidArgumentException;

final class SurfaceDefinition
{
    /** @param list<string> $include */
    public function __construct(
        public readonly string $name,
        public readonly array $include,
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $name) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Surface [%s] must use a valid surface name (letters, digits, dot, dash or underscore).',
                $name,
            ));
        }

        if ($include === []) {
            throw new InvalidArgumentException(sprintf(
                'Surface [%s] must declare at least one include path.',
                $name,
            ));
        }
    }

    public static function parse(string $name, mixed $configuration): self
    {
        if (! is_array($configuration)) {
            throw new InvalidArgumentException(sprintf(
                'Surface [%s] must map to an array with an include list.',
                $name,
            ));
        }

        foreach (array_keys($configuration) as $key) {
            if ($key !== 'include') {
                throw new InvalidArgumentException(sprintf(
                    'Surface [%s] has an unsupported key [%s]; only "include" is accepted.',
                    $name,
                    (string) $key,
                ));
            }
        }

        $include = $configuration['include'] ?? null;
        if (! is_array($include) || ! array_is_list($include)) {
            throw new InvalidArgumentException(sprintf(
                'Surface [%s] must declare include as a list of include paths.',
                $name,
            ));
        }

        $paths = [];
        foreach ($include as $path) {
            if (! is_string($path)) {
                throw new InvalidArgumentException(sprintf(
                    'Surface [%s] include paths must be strings.',
                    $name,
                ));
            }

            $paths[] = IncludePattern::normalize($path);
        }

        $paths = array_values(array_unique($paths));
        sort($paths);

        if ($paths === []) {
            throw new InvalidArgumentException(sprintf(
                'Surface [%s] must declare at least one include path.',
                $name,
            ));
        }

        return new self($name, $paths);
    }

    public function digest(): string
    {
        return hash('sha256', json_encode([
            'name' => $this->name,
            'include' => $this->include,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
