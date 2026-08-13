<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\EventDispatcher\EventSubscriberInterface;

final class ComposerSourcePlugin implements PluginInterface, EventSubscriberInterface
{
    private ?Composer $composer = null;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        $this->composer = null;
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        $this->composer = null;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'pre-pool-create' => 'selectPackageSources',
            ScriptEvents::POST_AUTOLOAD_DUMP => 'generateAliases',
        ];
    }

    /**
     * Select one configured package source before Composer resolves the pool.
     *
     * The event is intentionally untyped so the plugin remains installable
     * with Composer plugin API 2.0; pre-pool-create is available in newer
     * Composer 2 releases and is ignored when unavailable.
     */
    public function selectPackageSources(object $event): void
    {
        if (! $this->composer instanceof Composer || ! method_exists($event, 'getPackages')) {
            return;
        }

        (new PackageSourceSelector($this->composer, $event->getIO()))->select($event);
    }

    public function generateAliases(Event $event): void
    {
        if (! $this->composer instanceof Composer) {
            return;
        }

        $generator = new NamespaceAliasGenerator(
            $this->composer,
            $event->getIO(),
        );

        $generator->generate();
    }
}
