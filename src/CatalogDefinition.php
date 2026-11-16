<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

use InvalidArgumentException;

/** Author-owned discovery metadata; consumers explicitly opt in once. */
final class CatalogDefinition
{
    /** @param list<string> $packages */
    private function __construct(public readonly string $url, public readonly array $packages)
    {
    }

    public static function parse(mixed $value, bool $allowLocal = false): self
    {
        if (! is_array($value) || array_diff(array_keys($value), ['url', 'packages']) !== []) {
            throw new InvalidArgumentException('A catalog must contain only url and packages.');
        }
        $url = $value['url'] ?? null;
        $parts = is_string($url) ? parse_url($url) : false;
        $secure = is_array($parts) && ($parts['scheme'] ?? '') === 'https' && ! empty($parts['host']);
        $local = $allowLocal && is_array($parts) && ($parts['scheme'] ?? '') === 'file' && ! isset($parts['host']) && str_starts_with($parts['path'] ?? '', '/');
        if ((! $secure && ! $local) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || preg_match('/[\x00-\x20\x7f]/', (string) $url)) {
            throw new InvalidArgumentException('Catalog URL must be an absolute HTTPS index URL without credentials, query or fragment (file URLs require --allow-local).');
        }
        $packages = $value['packages'] ?? null;
        if (! is_array($packages) || ! array_is_list($packages) || $packages === []) {
            throw new InvalidArgumentException('Catalog packages must be a non-empty list of exact Composer package names.');
        }
        foreach ($packages as $package) {
            if (! is_string($package) || preg_match('{^[a-z0-9](?:[_.-]?[a-z0-9]+)*/[a-z0-9](?:[_.-]?[a-z0-9]+)*$}D', $package) !== 1) {
                throw new InvalidArgumentException('Catalog package filters must be exact package names, not wildcards.');
            }
        }
        $packages = array_values(array_unique($packages));
        sort($packages, SORT_STRING);
        return new self(rtrim($url, '/'), $packages);
    }

    /** @return array{type: string, url: string, canonical: bool, only: list<string>} */
    public function repository(): array
    {
        return ['type' => 'composer', 'url' => $this->url, 'canonical' => true, 'only' => $this->packages];
    }
}
