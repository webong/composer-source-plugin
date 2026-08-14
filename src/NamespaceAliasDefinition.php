<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

final readonly class NamespaceAliasDefinition
{
    public const TYPE_SIMPLE = 'simple';

    public const TYPE_REBASE = 'rebase';

    public function __construct(
        public string $sourcePrefix,
        public string $targetPrefix,
        public string $type = self::TYPE_SIMPLE,
        public ?string $package = null,
    ) {
    }

    public function appliesTo(string $package): bool
    {
        return $this->package === null || $this->package === $package;
    }
}
