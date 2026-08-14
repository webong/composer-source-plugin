<?php

declare(strict_types=1);

namespace Webong\ComposerSource\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Webong\ComposerSource\NamespaceAliasConfiguration;
use Webong\ComposerSource\NamespaceAliasDefinition;

final class NamespaceAliasConfigurationTest extends TestCase
{
    public function testItParsesTheLegacyFlatAliasMapAsSimpleDefinitions(): void
    {
        $definitions = NamespaceAliasConfiguration::parse([
            'Webong\\WebProxy\\' => 'Zorvia\\WebProxy\\',
        ]);

        self::assertCount(1, $definitions);
        self::assertSame('Webong\\WebProxy\\', $definitions[0]->sourcePrefix);
        self::assertSame('Zorvia\\WebProxy\\', $definitions[0]->targetPrefix);
        self::assertSame(NamespaceAliasDefinition::TYPE_SIMPLE, $definitions[0]->type);
        self::assertNull($definitions[0]->package);
    }

    public function testItParsesPackageScopedAliasesAsSimpleByDefault(): void
    {
        $definitions = NamespaceAliasConfiguration::parse([
            'webong/web-proxy' => [
                'Webong\\WebProxy\\' => 'Zorvia\\WebProxy\\',
            ],
        ]);

        self::assertCount(1, $definitions);
        self::assertSame(NamespaceAliasDefinition::TYPE_SIMPLE, $definitions[0]->type);
        self::assertSame('webong/web-proxy', $definitions[0]->package);
    }

    public function testItParsesPackageScopedRebaseAliases(): void
    {
        $definitions = NamespaceAliasConfiguration::parse([
            'webong/web-flow' => [
                'Webong\\WebFlow\\' => 'Zorvia\\WebFlow\\',
                'type' => 'rebase',
            ],
        ]);

        self::assertCount(1, $definitions);
        self::assertSame(NamespaceAliasDefinition::TYPE_REBASE, $definitions[0]->type);
        self::assertSame('webong/web-flow', $definitions[0]->package);
    }

    public function testItRejectsDuplicateNamespacePrefixesAcrossConfigurationStyles(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Webong\\WebProxy\\');

        NamespaceAliasConfiguration::parse([
            'Webong\\WebProxy\\' => 'Zorvia\\WebProxy\\',
            'webong/web-proxy' => [
                'Webong\\WebProxy\\' => 'Zorvia\\WebProxy\\',
            ],
        ]);
    }
}
