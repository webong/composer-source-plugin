<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

use InvalidArgumentException;

final class SurfaceDefinition
{
    /** @param list<string> $include @param list<string> $exclude @param list<string> $manifests */
    public function __construct(
        public readonly string $name,
        public readonly array $include,
        public readonly array $exclude = [],
        public readonly array $manifests = [],
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $name) !== 1) {
            throw new InvalidArgumentException(sprintf('Surface [%s] must use a valid surface name (letters, digits, dot, dash or underscore).', $name));
        }
        if ($include === []) {
            throw new InvalidArgumentException(sprintf('Surface [%s] must declare at least one include path.', $name));
        }
    }

    public static function parse(string $name, mixed $configuration): self
    {
        if (! is_array($configuration)) {
            throw new InvalidArgumentException(sprintf('Surface [%s] must map to an array with an include list.', $name));
        }
        if (array_is_list($configuration)) {
            $configuration = ['include' => $configuration];
        }
        foreach (array_keys($configuration) as $key) {
            if (! in_array($key, ['include', 'exclude'], true)) {
                throw new InvalidArgumentException(sprintf('Surface [%s] has an unsupported key [%s]; only include and exclude are accepted here.', $name, (string) $key));
            }
        }
        return new self($name, self::paths($name, 'include', $configuration['include'] ?? null), self::paths($name, 'exclude', $configuration['exclude'] ?? []));
    }

    /** @return list<string> */
    private static function paths(string $name, string $key, mixed $values): array
    {
        if (! is_array($values) || ! array_is_list($values)) {
            throw new InvalidArgumentException(sprintf('Surface [%s] must declare %s as a list of include paths or exclusion paths.', $name, $key));
        }
        $paths = [];
        foreach ($values as $path) {
            if (! is_string($path)) {
                throw new InvalidArgumentException(sprintf('Surface [%s] %s paths must be strings.', $name, $key));
            }
            $paths[] = IncludePattern::normalize($path);
        }
        $paths = array_values(array_unique($paths));
        sort($paths);
        return $paths;
    }

    public function matches(string $path): bool
    {
        return IncludePattern::matchesAny($this->include, $path) && ! IncludePattern::matchesAny($this->exclude, $path);
    }

    /** Preserve old digests when the new features are unused. @return array<string, mixed> */
    public function metadata(): array
    {
        $data = ['name' => $this->name, 'include' => $this->include];
        if ($this->exclude !== []) {
            $data['exclude'] = $this->exclude;
        }
        if ($this->manifests !== []) {
            $data['manifests'] = $this->manifests;
        }
        return $data;
    }

    public function digest(): string
    {
        return hash('sha256', json_encode($this->metadata(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
