<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

final readonly class MirrorResult
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
        public string $package,
        public array $written = [],
        public array $unchanged = [],
        public array $removed = [],
        public array $preserved = [],
        public array $conflicts = [],
        public array $warnings = [],
        public bool $unbased = false,
    ) {
    }

    public function hasChanges(): bool
    {
        return $this->written !== [] || $this->removed !== [];
    }
}
