<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

final class PublicationPackage
{
    /** @param array<string, mixed> $manifest */
    public function __construct(
        public readonly string $name,
        public readonly string $path,
        public readonly array $manifest,
    ) {
    }

    /** Full identity prevents cross-vendor branch and tag collisions. */
    public function short(): string
    {
        return $this->name;
    }

    public function branch(): string
    {
        return 'dist/'.$this->short();
    }

    public function tag(string $version): string
    {
        return $this->short().'/v'.$version;
    }

    public function tagGlob(): string
    {
        return $this->short().'/v*';
    }
}
