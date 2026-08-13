<?php

declare(strict_types=1);

namespace Webong\ComposerNamespaceAlias\Tests;

use PHPUnit\Framework\TestCase;

final class PackageSourceSelectorTest extends TestCase
{
    public function testSupportedSourcePreferencesAreDocumented(): void
    {
        $readme = (string) file_get_contents(__DIR__ . '/../README.md');

        self::assertStringContainsString('`local`, `external`, and `auto`', $readme);
        self::assertStringContainsString('zorvia/web-proxy', $readme);
    }
}
