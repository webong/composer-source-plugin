<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

final class NamespaceRebaser
{
    public function rebase(string $contents, NamespaceAliasDefinition $definition): string
    {
        $sourceNamespace = rtrim($definition->sourcePrefix, '\\');
        $targetNamespace = rtrim($definition->targetPrefix, '\\');

        return str_replace(
            [$sourceNamespace, str_replace('\\', '\\\\', $sourceNamespace)],
            [$targetNamespace, str_replace('\\', '\\\\', $targetNamespace)],
            $contents,
        );
    }
}
