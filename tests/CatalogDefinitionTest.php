<?php

declare(strict_types=1);

namespace Webong\ComposerSource\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webong\ComposerSource\CatalogDefinition;

final class CatalogDefinitionTest extends TestCase
{
    public function testItScopesTheRepositoryToExactAuthorDeclaredPackages(): void
    {
        $definition = CatalogDefinition::parse(['url' => 'https://example.com/packages/', 'packages' => ['acme/web', 'acme/core', 'acme/web']]);
        self::assertSame(['type' => 'composer', 'url' => 'https://example.com/packages', 'canonical' => true, 'only' => ['acme/core', 'acme/web']], $definition->repository());
    }

    public function testItPreservesTheHumanReadableIndexFilename(): void
    {
        $url = 'https://example.com/main/composer-packages.json';
        self::assertSame($url, CatalogDefinition::parse(['url' => $url, 'packages' => ['acme/core']])->repository()['url']);
    }

    #[DataProvider('invalidCatalogs')]
    public function testItRejectsUnsafeOrBroadCatalogs(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        CatalogDefinition::parse($value);
    }

    public static function invalidCatalogs(): iterable
    {
        foreach ([null, [], ['url' => 'https://example.com', 'packages' => []], ['url' => 'https://example.com', 'packages' => ['acme/*']], ['url' => 'https://example.com', 'packages' => ['Acme/core']], ['url' => 'https://example.com', 'packages' => ['acme/core'], 'canonical' => false]] as $value) {
            yield [$value];
        }
        foreach (['http://example.com', 'file:///tmp/catalog', 'https://user:secret@example.com', 'https://example.com/?token=secret', 'https://example.com/#fragment', "https://example.com/\n"] as $url) {
            yield [['url' => $url, 'packages' => ['acme/core']]];
        }
    }

    public function testLocalCatalogRequiresAnExplicitOptIn(): void
    {
        self::assertSame('file:///tmp/catalog', CatalogDefinition::parse(['url' => 'file:///tmp/catalog', 'packages' => ['acme/core']], true)->url);
    }
}
