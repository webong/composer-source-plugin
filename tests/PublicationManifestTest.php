<?php

declare(strict_types=1);

namespace Webong\ComposerSource\Tests;

use PHPUnit\Framework\TestCase;
use Webong\ComposerSource\PublicationException;
use Webong\ComposerSource\PublicationManifest;

final class PublicationManifestTest extends TestCase
{
    public function testItRejectsSiblingAutoloadingRatherThanPublishingABrokenRoot(): void
    {
        $this->expectException(PublicationException::class);
        PublicationManifest::validate(['name' => 'acme/core', 'autoload' => ['psr-4' => ['Acme\\' => '../shared']]]);
    }

    public function testItPreservesNormalRootRelativePaths(): void
    {
        PublicationManifest::validate(['bin' => ['bin/tool'], 'autoload' => ['psr-4' => ['Acme\\' => ['', 'src/']], 'files' => ['src/bootstrap.php'], 'exclude-from-classmap' => ['/Tests/']]]);
        $this->addToAssertionCount(1);
    }
}
