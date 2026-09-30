<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

final class NamespaceAliasDefinition
{
    public const TYPE_SIMPLE = 'simple';

    public const TYPE_REBASE = 'rebase';

    public const DESTINATION_GENERATED = 'generated';

    public const DESTINATION_MIRROR = 'mirror';

    public function __construct(
        public readonly string $sourcePrefix,
        public readonly string $targetPrefix,
        public readonly string $type = self::TYPE_SIMPLE,
        public readonly ?string $package = null,
        public readonly string $copy = 'package',
        public readonly array $include = [],
        public readonly array $exclude = [],
        public readonly string $destination = self::DESTINATION_GENERATED,
    ) {
    }

    public function appliesTo(string $package): bool
    {
        return $this->package === null || $this->package === $package;
    }
}
