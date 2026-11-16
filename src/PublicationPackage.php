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
        public readonly string $refNames = 'full',
    ) {
    }

    /** Full identity is the default; short refs are an explicit migration mode. */
    public function short(): string
    {
        return $this->refNames === 'short' ? substr($this->name, (int) strpos($this->name, '/') + 1) : $this->name;
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
