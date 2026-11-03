<?php

declare(strict_types=1);

namespace Webong\ComposerSource\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Webong\ComposerSource\Symfony\ComposerSourceAliasesPass;

final class ComposerSourceAliasesPassTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->files = [];
    }

    public function testRegistersAnAliasForAnExistingService(): void
    {
        $container = new ContainerBuilder();
        $container->register('Acme\\Library\\Client');

        $this->pass(['Acme\\Library\\Client' => 'Legacy\\Library\\Client'])->process($container);

        self::assertTrue($container->has('Legacy\\Library\\Client'));
        self::assertSame(
            'Acme\\Library\\Client',
            (string) $container->getAlias('Legacy\\Library\\Client'),
        );
    }

    public function testCompiledContainerKeepsAPublicAliasToAPublicService(): void
    {
        $container = new ContainerBuilder();
        $container->register('Acme\\Library\\Client')->setPublic(true);

        $this->pass(['Acme\\Library\\Client' => 'Legacy\\Library\\Client'])->process($container);
        $container->compile();

        self::assertTrue($container->has('Legacy\\Library\\Client'));
    }

    public function testSkipsClassesThatAreNotRegisteredAsServices(): void
    {
        $container = new ContainerBuilder();

        $this->pass(['Acme\\Library\\Client' => 'Legacy\\Library\\Client'])->process($container);

        self::assertFalse($container->has('Legacy\\Library\\Client'));
    }

    public function testDoesNotReplaceAnExistingAlias(): void
    {
        $container = new ContainerBuilder();
        $container->register('Acme\\Library\\Client');
        $container->register('Other\\Client');
        $container->setAlias('Legacy\\Library\\Client', 'Other\\Client');

        $this->pass(['Acme\\Library\\Client' => 'Legacy\\Library\\Client'])->process($container);

        self::assertSame(
            'Other\\Client',
            (string) $container->getAlias('Legacy\\Library\\Client'),
        );
    }

    public function testDoesNotReplaceAnExistingDefinition(): void
    {
        $container = new ContainerBuilder();
        $container->register('Acme\\Library\\Client');
        $container->register('Legacy\\Library\\Client');

        $this->pass(['Acme\\Library\\Client' => 'Legacy\\Library\\Client'])->process($container);

        self::assertTrue($container->hasDefinition('Legacy\\Library\\Client'));
    }

    public function testKeepsPublicServicesReachableThroughTheAlias(): void
    {
        $container = new ContainerBuilder();
        $container->register('Acme\\Library\\Client')->setPublic(true);

        $this->pass(['Acme\\Library\\Client' => 'Legacy\\Library\\Client'])->process($container);

        self::assertTrue($container->getAlias('Legacy\\Library\\Client')->isPublic());
    }

    public function testPrivateServicesStayPrivateThroughTheAlias(): void
    {
        $container = new ContainerBuilder();
        $container->register('Acme\\Library\\Client')->setPublic(false);

        $this->pass(['Acme\\Library\\Client' => 'Legacy\\Library\\Client'])->process($container);

        self::assertFalse($container->getAlias('Legacy\\Library\\Client')->isPublic());
    }

    public function testSkipsSelfAliasesAndInvalidEntries(): void
    {
        $container = new ContainerBuilder();
        $container->register('Acme\\Self\\Client');
        $container->register('Acme\\Empty\\Client');
        $container->register('Acme\\Invalid\\Client');

        $this->pass(['Acme\\Self\\Client' => 'Acme\\Self\\Client'])->process($container);
        $this->pass(['Acme\\Empty\\Client' => ''])->process($container);
        $this->pass(['Acme\\Invalid\\Client' => 42])->process($container);

        self::assertSame([], $container->getAliases());
    }

    public function testMissingAliasFileIsIgnored(): void
    {
        $container = new ContainerBuilder();
        $container->register('Acme\\Library\\Client');

        (new ComposerSourceAliasesPass(sys_get_temp_dir() . '/missing-source-aliases-' . bin2hex(random_bytes(6)) . '.php'))
            ->process($container);

        self::assertFalse($container->has('Legacy\\Library\\Client'));
    }

    public function testNonArrayAliasFileIsIgnored(): void
    {
        $file = sys_get_temp_dir() . '/source-aliases-' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($file, "<?php\n\nreturn 'not a map';\n");
        $this->files[] = $file;

        $container = new ContainerBuilder();
        $container->register('Acme\\Library\\Client');

        (new ComposerSourceAliasesPass($file))->process($container);

        self::assertFalse($container->has('Legacy\\Library\\Client'));
    }

    /** @param array<mixed> $aliases */
    private function pass(array $aliases): ComposerSourceAliasesPass
    {
        $file = sys_get_temp_dir() . '/source-aliases-' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($file, "<?php\n\nreturn " . var_export($aliases, true) . ";\n");
        $this->files[] = $file;

        return new ComposerSourceAliasesPass($file);
    }
}
