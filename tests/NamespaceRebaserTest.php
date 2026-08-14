<?php

declare(strict_types=1);

namespace Webong\ComposerSource\Tests;

use PHPUnit\Framework\TestCase;
use Webong\ComposerSource\NamespaceAliasDefinition;
use Webong\ComposerSource\NamespaceRebaser;

final class NamespaceRebaserTest extends TestCase
{
    public function testItRebasesDeclarationsReferencesAndClassStrings(): void
    {
        $contents = <<<'PHP'
<?php

namespace Webong\WebProxy;

use Webong\WebProxy\Contracts\Handler;

final class Endpoint implements Handler
{
    public const HANDLER = 'Webong\\WebProxy\\Handlers\\EndpointHandler';

    public function handle(\Webong\WebProxy\Request $request): Webong\WebProxy\Response
    {
    }
}
PHP;

        $rebased = (new NamespaceRebaser)->rebase($contents, new NamespaceAliasDefinition(
            'Webong\\WebProxy\\',
            'Zorvia\\WebProxy\\',
            NamespaceAliasDefinition::TYPE_REBASE,
            'webong/web-proxy',
        ));

        self::assertStringContainsString('namespace Zorvia\\WebProxy;', $rebased);
        self::assertStringContainsString('use Zorvia\\WebProxy\\Contracts\\Handler;', $rebased);
        self::assertStringContainsString('\\Zorvia\\WebProxy\\Request', $rebased);
        self::assertStringContainsString('Zorvia\\WebProxy\\Response', $rebased);
        self::assertStringContainsString('Zorvia\\\\WebProxy\\\\Handlers\\\\EndpointHandler', $rebased);
    }
}
