<?php

declare(strict_types=1);

namespace Webong\ComposerSource\Tests;

use PHPUnit\Framework\TestCase;

final class PackageSourceSelectorTest extends TestCase
{
    public function testSupportedSourcePreferencesAreDocumented(): void
    {
        $readme = (string) file_get_contents(__DIR__ . '/../README.md');

        self::assertStringContainsString('`inline`', $readme);
        self::assertStringContainsString('`outline`', $readme);
        self::assertStringContainsString('`auto`', $readme);
        self::assertStringContainsString('zorvia/web-proxy', $readme);
        self::assertStringContainsString('"loaders"', $readme);
        self::assertStringContainsString('"type": "auto"', $readme);
        self::assertStringNotContainsString('"preference"', $readme);
        self::assertStringContainsString('"path"', $readme);
        self::assertStringContainsString('"manifest"', $readme);
        self::assertStringNotContainsString('"local_path"', $readme);
        self::assertStringNotContainsString('"local_manifest"', $readme);
        self::assertStringContainsString('"aliases"', $readme);
    }
}
