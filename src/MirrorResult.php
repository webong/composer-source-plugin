<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

final class MirrorResult
{
    /**
     * @param list<string> $written   files taken from upstream
     * @param list<string> $unchanged files already matching upstream
     * @param list<string> $removed   files deleted because upstream dropped them
     * @param list<string> $preserved files kept because the local copy differs and upstream did not touch them
     * @param list<string> $conflicts files both sides changed; left untouched locally
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly string $package,
        public readonly array $written = [],
        public readonly array $unchanged = [],
        public readonly array $removed = [],
        public readonly array $preserved = [],
        public readonly array $conflicts = [],
        public readonly array $warnings = [],
        public readonly bool $unbased = false,
    ) {
    }

    public function hasChanges(): bool
    {
        return $this->written !== [] || $this->removed !== [];
    }
}
